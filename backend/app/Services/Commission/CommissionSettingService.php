<?php

namespace App\Services\Commission;

use App\Enums\CommissionBasis;
use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 2026-09-12 — the company-level commission settings, read and written
 * through commission's own door.
 *
 * ── WHY THIS EXISTS, WHEN THE COLUMNS LIVE ON `companies` ──
 *
 * The admin commission screen needs two facts about the company: which base
 * its percentages apply to (`commission_basis`) and which plan it runs
 * (`commission_plan_type`). Both are columns on the company row, so the
 * screen first read them with GET /companies/{id}.
 *
 * That worked, and it worked by leaning on an exception. `routes/api.php`
 * describes the companies resource as "Super Admin only end to end", which is
 * true of index/store/update/destroy and NOT of show — CompanyPolicy::view has
 * always also allowed a user to read their OWN company. The whole screen
 * balanced on that one clause.
 *
 * The owner's point (2026-09-12): somebody tightening CompanyPolicy::view to
 * `isSuperAdmin()` alone — on the strength of that very comment — would break
 * nothing visible. No exception, no failing test near the change. A Company
 * Admin's screen would simply catch the 403 and render its defaults: 'ราคาขาย'
 * shown as the selected basis at a company that pays on PV. A confident, wrong
 * answer about how their agents are paid.
 *
 * So the dependency is removed rather than documented. This is the same shape
 * every other per-company commission setting already has
 * (CommissionSplitSettingService, CommissionBinarySettingService,
 * CommissionMatrixSettingService, …): forCompany() + a narrow write, its own
 * endpoint, its own Ability. The earlier read through the platform CRUD
 * resource was the odd one out.
 *
 * ── BOTH FIELDS, AND WHY THE PLAN TYPE JOINED THEM ──
 *
 * `commission_basis` moved here first. `commission_plan_type` followed the
 * same day, for a reason that showed up as a UI complaint rather than an
 * architectural one: the owner pressed "เปลี่ยนแผน" on step 2 of the
 * commission screen and was thrown onto /companies to finish
 * ("ทำให้ UI สับสน"). That link existed only because the write lived behind
 * CompanyPolicy::update and the screen could not perform it. Moving the write
 * removed the reason for the link, and the two fields are the same sentence
 * anyway — "how does this company pay": the plan says who gets paid, the basis
 * says what a percentage is a percentage of.
 *
 * `PUT /companies/{id}` no longer accepts the plan type. One column, one write
 * door — CommissionBasisVisibilityTest is the tripwire on the closed one.
 * Company CREATION still carries it, which is a different operation: a tenant
 * is provisioned with a plan, it is not an edit of an existing value.
 */
class CommissionSettingService
{
    /** How many names the uncertified-leader warning lists before it stops and gives a count instead. */
    private const LEADER_WARNING_SAMPLE = 10;

    public function __construct(
        // Only for deepest_manager_chain below — the screen needs the same
        // number the save-time refusal uses, or it would show a maximum the
        // server then disagrees with.
        private readonly OverrideDeductionGuard $deductionGuard,
    ) {}

    /**
     * Always returns a value, never null — the same contract every sibling
     * service holds. A caller must not have to tell "not configured" from
     * "configured": every company HAS a basis (the column defaults to
     * 'price') and a plan, and making the screen guess which it is looking at
     * is how it ends up asserting one it was never told.
     *
     * A null company id is a Super Admin on "ทุกบริษัท", where the question
     * has no single answer. Price and null are returned as the honest
     * placeholder — the screen refuses to render step 2 in that state
     * anyway — rather than picking one tenant's settings to speak for all.
     *
     * @return array{commission_basis: CommissionBasis, commission_plan_type: CommissionPlanType|null, commission_override_mode: CommissionOverrideMode, deepest_manager_chain: int, plan_locked_by_sales: array{locked: bool, paid_orders: int, ledger_rows: int, first_sale_at: string|null}, commission_house_account: array{id: int, name: string, agents_under: int, earned_satang: int}|null, leaders_missing_certification: array{total: int, leaders: list<array{id: int, name: string, agents_under: int}>}}
     */
    public function forCompany(?int $companyId): array
    {
        $company = $companyId === null ? null : Company::find($companyId);

        return [
            'commission_basis' => $company?->commission_basis ?? CommissionBasis::Price,
            'commission_plan_type' => $company?->commission_plan_type,
            // 2026-09-13 — where the leader's share comes from.
            'commission_override_mode' => $company?->commission_override_mode ?? CommissionOverrideMode::Additive,
            /*
             * 2026-09-19 — how far up the chain a leader override reaches,
             * and whether a skipped manager's level is inherited.
             *
             * Both are WRITTEN through update() already; without them here the
             * screen that sets them could not show what is currently set, so
             * every visit would render an empty field over a live value and a
             * save would look like it had done nothing.
             *
             * max_override_depth stays NULLABLE all the way to the client. It
             * is the one setting on this endpoint whose null is a real answer
             * ("as far as the chain goes"), and coalescing it to a number here
             * would invent a cap nobody set — see update()'s own note.
             */
            'max_override_depth' => $company?->max_override_depth,
            'override_compression' => (bool) ($company?->override_compression ?? false),
            /*
             * How many managers a sale can have above it, at worst. Sent so
             * step 4 can show the arithmetic BEFORE somebody picks a deduct
             * mode — the owner's whole complaint was that the most
             * misunderstood number in the system had nothing on screen
             * explaining it. Zero for a company with no hierarchy yet, which
             * is a real and common answer, not a missing one.
             */
            'deepest_manager_chain' => $company === null ? 0 : $this->deductionGuard->deepestChain($company),
            /*
             * 2026-09-21 — CAN THE PLAN AND THE BASIS STILL BE CHANGED AT ALL?
             *
             * Owner: "ที่เราเคยสรุปกันไว้ไม่ใช่เหรอว่าหากมีการขายเกิดขึ้นแล้ว
             * การ Setup เปลี่ยนแผนค่าแนะนำจะทำไม่ได้". They are right, and the
             * refusal has existed since 2026-09-19
             * (assertNotSettledByExistingSales below) — but only as a refusal.
             * The screen had no way to know, so it kept offering the switch
             * with a banner saying the change "มีผลกับการขายครั้งถัดไปเท่านั้น",
             * and the admin learned otherwise only after pressing.
             *
             * A refusal that arrives after the press is a bug in the screen,
             * not a safety feature. So the same fact the guard uses is now
             * READ here: same helper, same two queries, so the sentence on
             * screen and the sentence in the error can never drift apart.
             */
            'plan_locked_by_sales' => $this->settledSalesSummary($company),
            /*
             * 2026-09-15 — the company's own seat in its hierarchy, or null.
             *
             * Sent with the two counts the screen needs to say what the seat
             * IS doing rather than only that it exists: how many people report
             * to it, and what it has been paid so far. A box that could only
             * say "on" would leave an admin no way to tell a seat that is
             * earning from one that is attached to nobody.
             */
            'commission_house_account' => $this->houseAccountPayload($company),
            /*
             * 2026-09-15 — the leaders this plan will silently pay nothing.
             *
             * ADR-035: a cert tier is a GATE on being paid an override. A
             * manager who has never passed one is skipped by
             * CommissionService's chain walk — no error, no log, no row. The
             * company sets a leader rate in step 4, the sale completes, the
             * seller is paid, and the leader simply is not, with nothing
             * anywhere saying why. The owner's own bug report earlier today
             * was this shape ("ค่าคอมตัวแทนไม่ได้คำนวณการตัดให้หัวหน้าทีม")
             * with a different cause, and this is the next cause in line.
             *
             * Computed for every read of this endpoint rather than behind a
             * flag: it is two queries, and the whole point is that nobody
             * goes looking for a failure they have not been told exists.
             */
            'leaders_missing_certification' => $this->leadersMissingCertification($company),
        ];
    }

    /**
     * Managers with people under them and no certification at all.
     *
     * ── THE DEFINITION IS MIRRORED, AND THAT IS A RISK ──
     *
     * `User::highestPassedCertTier()` is what actually decides at payout
     * time, and it is an per-row lookup this could not use without N+1. So
     * this is a SECOND expression of "has a certification", written as a
     * whereDoesntHave. The two must agree, or the screen reassures an admin
     * about a leader the payout then skips — the same defect shape as the
     * duplicated resolution ladder that once paid a Thai Life rate on an AIA
     * product. CommissionSettingTest pins them together; if
     * highestPassedCertTier() ever grows a condition (a pass/fail status, an
     * expiry), that test fails and this query has to follow it.
     *
     * Not narrowed to `role = agent`: the chain walk follows `manager_id`
     * whatever the role, so whoever is standing in that chain is who this
     * has to be about.
     *
     * @return array{total: int, leaders: list<array{id: int, name: string, agents_under: int}>}
     */
    private function leadersMissingCertification(?Company $company): array
    {
        if ($company === null) {
            return ['total' => 0, 'leaders' => []];
        }

        $query = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            // The house account is the deliberate exemption from the cert
            // gate (CommissionService) — it IS paid without one, so warning
            // about it would be a warning about correct behaviour.
            ->when(
                $company->commission_house_user_id !== null,
                fn ($builder) => $builder->whereKeyNot($company->commission_house_user_id),
            )
            ->whereHas('directReports')
            ->whereDoesntHave('certifications');

        $leaders = (clone $query)
            ->withCount('directReports')
            ->orderByDesc('direct_reports_count')
            ->limit(self::LEADER_WARNING_SAMPLE)
            ->get();

        return [
            // The full count, even when the list below is capped: "3 of 47"
            // is a different situation from "3", and a screen that can only
            // show the sample would present the second.
            'total' => $query->count(),
            'leaders' => $leaders->map(fn (User $leader) => [
                'id' => (int) $leader->id,
                'name' => (string) $leader->name,
                'agents_under' => (int) $leader->direct_reports_count,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{id: int, name: string, agents_under: int, earned_satang: int, bank_name: string|null, bank_account_number: string|null, bank_account_holder_name: string|null, payout_details_complete: bool}|null
     */
    private function houseAccountPayload(?Company $company): ?array
    {
        $house = $company?->commissionHouseAccount;

        if ($house === null) {
            return null;
        }

        return [
            'id' => (int) $house->id,
            'name' => $house->name,
            'agents_under' => User::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('manager_id', $house->id)
                ->count(),
            /*
             * EVERY row, paid and unpaid alike — this is "what the company has
             * earned as a leader", not "what it is owed". The company is never
             * owed anything by itself; the number exists so an admin can see
             * the setting doing something.
             */
            'earned_satang' => (int) CommissionLedger::withoutGlobalScopes()
                ->where('agent_id', $house->id)
                ->sum('amount_satang'),
            /*
             * 2026-09-15 (ครั้งที่สอง) — where the company's own share is
             * transferred to, now that it is a payee.
             *
             * Shown in full, not masked: this whole payload is behind
             * Ability::SettingsCommissionPlanUpdate, the same screen already
             * shows every agent's real account number on the payout run
             * (TASK-047, "แสดงเลยครับ เพราะต้องใช้งาน"), and an account number
             * that has to be checked against a bank file cannot be checked
             * with four of its digits hidden.
             */
            'bank_name' => $house->bank_name,
            'bank_account_number' => $house->bank_account_number,
            'bank_account_holder_name' => $house->bank_account_holder_name,
            // The screen needs to say WHY ตั้งจ่าย is refused before the admin
            // presses it, and this is the same method that refuses.
            'payout_details_complete' => $house->hasCompletePayoutDetails(),
        ];
    }

    /**
     * Writes whichever of the three was supplied, audits whichever actually
     * changed, and returns the settled state.
     *
     * Nullable parameters mean "not supplied", never "clear it" — none of the
     * three columns is nullable and none has an unset state. The Form Request
     * refuses a call that supplies none, so a no-op here is a no-op the
     * caller asked for (saving the value already in place), not an empty
     * request that silently succeeded.
     *
     * @return array{commission_basis: CommissionBasis, commission_plan_type: CommissionPlanType|null, commission_override_mode: CommissionOverrideMode, deepest_manager_chain: int, commission_house_account: array{id: int, name: string, agents_under: int, earned_satang: int}|null, leaders_missing_certification: array{total: int, leaders: list<array{id: int, name: string, agents_under: int}>}}
     */
    public function update(int $companyId, ?CommissionBasis $basis = null, ?CommissionPlanType $planType = null, ?CommissionOverrideMode $overrideMode = null, ?User $actor = null, bool $depthSupplied = false, ?int $maxOverrideDepth = null, ?bool $compression = null): array
    {
        $company = Company::findOrFail($companyId);

        if ($basis !== null) {
            $this->assertNotSettledByExistingSales(
                $company,
                'commission_basis',
                ($company->commission_basis ?? CommissionBasis::Price)->value,
                $basis->value,
                'ฐานการคำนวณ',
            );

            $this->applyChange(
                $company,
                'commission_basis',
                ($company->commission_basis ?? CommissionBasis::Price)->value,
                $basis->value,
                'commission_basis.updated',
                $actor,
            );
        }

        if ($planType !== null) {
            $this->assertNotSettledByExistingSales(
                $company,
                'commission_plan_type',
                $company->commission_plan_type?->value,
                $planType->value,
                'แผนค่าแนะนำ',
            );

            $this->applyChange(
                $company,
                'commission_plan_type',
                $company->commission_plan_type?->value,
                $planType->value,
                'commission_plan_type.updated',
                $actor,
            );
        }

        if ($depthSupplied) {
            /*
             * 2026-09-19 — how far up the chain a leader override reaches.
             *
             * Deliberately NOT guarded by assertNotSettledByExistingSales().
             * Capping the depth changes who gets paid on the NEXT sale, the
             * same as any rate change, and rate changes have never been
             * frozen by past sales — only the two settings that change the
             * MEANING of every row (what a percentage is a percentage OF, and
             * which plan is being run) are.
             *
             * Written here rather than through applyChange() because null is
             * a real value for this column ("no cap") and applyChange takes a
             * non-null string: routing null through it would write an empty
             * string, which the integer cast turns into 0 — a cap of zero
             * levels, silently paying nobody.
             *
             * Audited either way (§6): a cap moves money away from people who
             * were being paid yesterday.
             */
            $before = $company->max_override_depth;

            if ($before !== $maxOverrideDepth) {
                $company->update(['max_override_depth' => $maxOverrideDepth]);

                if ($actor) {
                    AuditLog::create([
                        'company_id' => $company->id,
                        'actor_user_id' => $actor->id,
                        'action' => 'commission_max_override_depth.updated',
                        'auditable_type' => Company::class,
                        'auditable_id' => $company->id,
                        'old_values' => ['max_override_depth' => $before],
                        'new_values' => ['max_override_depth' => $maxOverrideDepth],
                        'ip_address' => request()?->ip(),
                    ]);
                }
            }
        }

        if ($compression !== null && (bool) $company->override_compression !== $compression) {
            /*
             * 2026-09-19 — turning compression on moves money from the levels
             * below a skipped manager to the people above them. Not frozen by
             * past sales for the same reason a rate change is not: it changes
             * the NEXT sale, not the meaning of the rows already written.
             * Audited because it moves money (§6).
             */
            $before = (bool) $company->override_compression;
            $company->update(['override_compression' => $compression]);

            if ($actor) {
                AuditLog::create([
                    'company_id' => $company->id,
                    'actor_user_id' => $actor->id,
                    'action' => 'commission_override_compression.updated',
                    'auditable_type' => Company::class,
                    'auditable_id' => $company->id,
                    'old_values' => ['override_compression' => $before],
                    'new_values' => ['override_compression' => $compression],
                    'ip_address' => request()?->ip(),
                ]);
            }
        }

        if ($overrideMode !== null) {
            $this->assertModeFitsExistingRules($company, $overrideMode);

            $this->applyChange(
                $company,
                'commission_override_mode',
                ($company->commission_override_mode ?? CommissionOverrideMode::Additive)->value,
                $overrideMode->value,
                'commission_override_mode.updated',
                $actor,
            );
        }

        return $this->forCompany($companyId);
    }

    /**
     * 2026-09-13 — the second half of "ห้ามตั้งเรทที่หักเกิน".
     *
     * OverrideDeductionGuard sits on the rate form, which catches the admin
     * who raises a rate under a deduct mode. It cannot catch the opposite
     * order, and the opposite order is the likely one: every rate set while
     * the company was on Additive was approved WITHOUT the guard ever running
     * — nothing was coming out of a pool, so there was no pool to empty. Flip
     * the mode afterwards and all of them start deducting at once.
     *
     * Without this, that flip is silent. CommissionService's runtime cap keeps
     * the seller's row from going negative, so nothing errors: the leaders
     * simply stop being paid, and the first anyone hears of it is a payout
     * that is missing. So the switch is refused with the same message the rate
     * form uses, which already names the maximum and the three ways out.
     *
     * Only rules that are LIVE are checked. An expired row cannot deduct from
     * anything, and refusing a mode switch because of a rate that stopped
     * applying last year would be a lock with no key.
     *
     * 2026-09-14 — and only rules that INHERIT this setting. A rate carrying
     * its own `override_mode` is not affected by this switch at all, so
     * blocking the switch on its behalf would refuse a change that cannot
     * touch it. Its own mode was checked when it was saved, by the same guard.
     */
    /**
     * 2026-09-19 — a company that has already paid somebody may not change
     * WHAT it pays on, or WHO it pays.
     *
     * ═══ WHY THIS DID NOT EXIST, AND WHY IT HAD TO ═══
     *
     * Only `commission_override_mode` was ever guarded here. Basis and plan
     * type went straight to applyChange() with no precondition at all, so a
     * company mid-year could move from ราคาขาย to PV, or from Unilevel to
     * Binary, with nothing between the click and the save. Owner, 2026-09-19:
     * "ผมปรับความตั้งใจผมการ Setup ค่าคอม ครั้งเดียวใช้ทั้งบริษัท และไม่ควร
     * เปลี่ยนหากมียอดขายเกิดขึ้นแล้ว".
     *
     * The audit log was the only thing watching, and an audit log explains a
     * change after it has happened — it does not prevent one. What it would
     * have been explaining is two eras of commission under one promise:
     * BR-4 makes every row written before the switch permanently
     * uncorrectable, so the company ends up owing one answer to "how am I
     * paid" and holding two sets of rows that answer differently.
     *
     * ═══ WHY THE FIRST LEDGER ROW IS THE LINE ═══
     *
     * Not "has agents", not "has rules" — those are setup, and setup is
     * exactly when these two settings are supposed to be chosen. The moment
     * that cannot be taken back is the moment money was booked against the
     * old answer. Before it, the screen is a setup form; after it, it is a
     * promise already kept once.
     *
     * Reversal rows count deliberately: a company whose only rows are
     * reversals still paid, and then unpaid, real people under the old rule.
     *
     * ═══ WHAT IT DELIBERATELY DOES NOT DO ═══
     *
     * It does not offer a force flag, and it does not offer a migration. A
     * company that genuinely has to switch is having a conversation about
     * money with its agents, not filling in a form — and whatever it decides
     * about the rows already written is a business decision (BR-7) that no
     * code here is entitled to make. Refusing is the honest stopping point.
     *
     * A no-op save (choosing the value already in place) is not a change and
     * is never refused — the Form Request lets the screen re-send all three
     * settings together, and refusing the two that did not move would make
     * the third unsavable forever.
     */
    /**
     * Has this company paid anything yet, and since when?
     *
     * ── WHY THIS IS SHARED WITH THE READ ──
     *
     * 2026-09-21. The refusal below and the banner on step 2 are two
     * statements about the same fact, and for two days only the refusal
     * existed: the screen went on offering the switch under a banner reading
     * "การสลับแผนมีผลกับการขายครั้งถัดไปเท่านั้น", and the admin found out
     * otherwise by pressing the button.
     *
     * Fixing that by counting ledger rows a second time in the read would
     * have created the failure mode this whole file keeps removing — two
     * expressions of one rule, free to drift, discovered at a payout. So the
     * count lives here and both callers ask it.
     *
     * ── WHAT COUNTS AS "HAS SOLD" — OWNER'S RULING, 2026-09-21 ──
     *
     * A PAID ORDER, or a commission row. Either one alone is enough.
     *
     * This shipped counting only `commission_ledger`, and the owner caught it
     * against their own production data: SWS had fourteen orders marked
     * ชำระเงินแล้ว and the screen still offered to switch the plan. "คือมันมี
     * Order ไง".
     *
     * The ledger-only reading had a defensible argument — nothing is booked,
     * so nothing can be contradicted — and it is the wrong one, for a reason
     * the fourteen orders demonstrate. A paid order whose commission has NOT
     * fired yet is not a sale that pays nothing; it is a sale whose payout is
     * still outstanding, for a fixable reason (no matching rate, an
     * uncertified seller, an order with no referral attached). The moment
     * that reason is fixed, the payout is computed under WHATEVER PLAN IS IN
     * FORCE THEN. So the ledger-only rule quietly allowed the one sequence
     * this guard exists to prevent: sell under plan A, switch to plan B, pay
     * the earlier sale under B.
     *
     * Refunded orders count too, and for the same reason reversal rows do:
     * Refunded is reachable only from Paid (OrderStatus), so the money
     * arrived and went back — the promise was still made under the old rule.
     *
     * Pending and AwaitingVerification do NOT count. Nobody has paid, the
     * order may yet be cancelled, and a company would otherwise be locked out
     * of its own setup by a cart somebody abandoned.
     *
     * `locked` is the answer to "may the plan or the basis change", and one
     * of either kind is enough — a single sale means money has been promised
     * under one set of rules.
     *
     * @return array{locked: bool, paid_orders: int, ledger_rows: int, first_sale_at: string|null}
     */
    private function settledSalesSummary(?Company $company): array
    {
        $none = ['locked' => false, 'paid_orders' => 0, 'ledger_rows' => 0, 'first_sale_at' => null];

        if ($company === null) {
            // A Super Admin on "ทุกบริษัท" is not asking about any company, so
            // nothing is locked — and step 2 refuses to render in that state
            // anyway. Reporting a lock here would put a refusal banner over a
            // screen that is not offering the choice.
            return $none;
        }

        /*
         * withoutGlobalScopes on both: this runs for a Super Admin acting on a
         * company that is not their own active one, and TenantScope would
         * silently answer 0 — which reads as "never sold anything" and unlocks
         * the very screen this is guarding.
         */
        $paidOrders = Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Refunded->value])
            ->count();

        $ledgerRows = CommissionLedger::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->count();

        if ($paidOrders === 0 && $ledgerRows === 0) {
            return $none;
        }

        /*
         * The earliest of the two, because the banner says "since when" and
         * the honest answer is when this company first sold anything — which
         * is the order, not the commission row that may have followed it days
         * later or never.
         *
         * COALESCE(paid_at, created_at): paid_at is nullable and older rows
         * predate it. Falling back to created_at names a date that is at
         * worst early, where a NULL would drop the row out of the MIN
         * entirely and report a first sale later than the real one.
         */
        $firstOrderAt = Order::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Refunded->value])
            ->min(DB::raw('COALESCE(paid_at, created_at)'));

        $firstLedgerAt = CommissionLedger::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->min('created_at');

        $candidates = array_values(array_filter([$firstOrderAt, $firstLedgerAt]));
        $firstAt = $candidates === [] ? null : min(array_map(
            static fn (string $raw): Carbon => Carbon::parse($raw),
            $candidates,
        ));

        return [
            'locked' => true,
            'paid_orders' => $paidOrders,
            'ledger_rows' => $ledgerRows,
            // ISO, not the d/m/Y the refusal prints: the screen formats dates
            // in the reader's own calendar (the admin runs on Buddhist years),
            // and a pre-formatted string would arrive already wrong there.
            'first_sale_at' => $firstAt?->toIso8601String(),
        ];
    }

    /**
     * How the refusal and the banner describe what is holding the lock.
     *
     * Three shapes rather than one, because "มีค่าแนะนำลงบัญชีแล้ว 0 รายการ"
     * over fourteen paid orders is the sentence that sent the owner looking
     * for a bug in the lock. The reader has to be able to recognise their own
     * situation in it.
     *
     * @param  array{locked: bool, paid_orders: int, ledger_rows: int, first_sale_at: string|null}  $settled
     */
    private function settledSalesReason(array $settled): string
    {
        $orders = number_format($settled['paid_orders']);
        $rows = number_format($settled['ledger_rows']);

        if ($settled['paid_orders'] > 0 && $settled['ledger_rows'] > 0) {
            return "บริษัทนี้ขายไปแล้ว {$orders} ออเดอร์ และมีค่าแนะนำลงบัญชีแล้ว {$rows} รายการ";
        }

        if ($settled['paid_orders'] > 0) {
            // The state the owner was actually in. Naming it explicitly stops
            // the screen implying the orders do not count.
            return "บริษัทนี้มีออเดอร์ที่ชำระเงินแล้ว {$orders} รายการ (ค่าแนะนำจะคิดตามแผนที่ตั้งไว้ตอนขาย)";
        }

        // Possible without any order: a renewal commission, or a promotion
        // bonus written straight to the ledger.
        return "บริษัทนี้มีค่าแนะนำที่ลงบัญชีไปแล้ว {$rows} รายการ";
    }

    private function assertNotSettledByExistingSales(
        Company $company,
        string $column,
        ?string $before,
        string $after,
        string $label,
    ): void {
        if ($before === $after) {
            return;
        }

        $settled = $this->settledSalesSummary($company);

        if (! $settled['locked']) {
            return;
        }

        throw ValidationException::withMessages([
            $column => sprintf(
                'เปลี่ยน%sไม่ได้แล้ว เพราะ%s (รายการแรก %s) — '
                .'ค่าแนะนำที่จ่ายไปแล้วแก้ย้อนหลังไม่ได้ ถ้าเปลี่ยนตอนนี้ ยอดก่อนและหลังจะคิดคนละกติกาโดยที่ไม่มีทางทำให้ตรงกันได้',
                $label,
                $this->settledSalesReason($settled),
                $settled['first_sale_at'] ? Carbon::parse($settled['first_sale_at'])->format('d/m/Y') : '—',
            ),
        ]);
    }

    private function assertModeFitsExistingRules(Company $company, CommissionOverrideMode $mode): void
    {
        if (! $mode->deductsFromSeller()) {
            return;
        }

        $liveInheritingRules = CommissionOverrideRule::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('override_mode')
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->get();

        foreach ($liveInheritingRules as $rule) {
            $refusal = $this->deductionGuard->refusalFor(
                $company,
                $rule->rate_type,
                (int) $rule->rate_value,
                $mode,
                $rule->product_id,
                $rule->product_category_id,
            );

            if ($refusal !== null) {
                throw ValidationException::withMessages([
                    'commission_override_mode' => 'เปลี่ยนเป็นโหมดนี้ไม่ได้ เพราะอัตราหัวหน้าทีมที่ตั้งไว้แล้วจะหักเกิน — '.$refusal,
                ]);
            }
        }
    }

    /**
     * Section 6 — "record every action that affects money [or] commission."
     *
     * These two are audited where the other per-company settings services are
     * not, and the line is the same one CommissionSplitSettingService draws:
     * those do not move money, and these do. Between them they decide who is
     * paid and what they are paid a percentage of, so one write changes the
     * amount of every future payout on every product.
     *
     * Rows already in the ledger are untouched (BR-4), which is precisely why
     * the MOMENT of the switch has to be recorded somewhere — it is the only
     * thing that explains why two rows for the same product, a week apart, do
     * not agree.
     *
     * Nothing is written and nothing is logged when the value did not move: an
     * audit trail padded with writes that changed nothing is one nobody reads.
     */
    private function applyChange(Company $company, string $column, ?string $before, string $after, string $action, ?User $actor): void
    {
        if ($before === $after) {
            return;
        }

        $company->update([$column => $after]);

        if (! $actor) {
            return;
        }

        AuditLog::create([
            'company_id' => $company->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => Company::class,
            'auditable_id' => $company->id,
            'old_values' => [$column => $before],
            'new_values' => [$column => $after],
            'ip_address' => request()?->ip(),
        ]);
    }
}
