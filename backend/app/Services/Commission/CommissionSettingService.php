<?php

namespace App\Services\Commission;

use App\Enums\CommissionBasis;
use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionPlanType;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\Company;
use App\Models\User;
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
     * @return array{commission_basis: CommissionBasis, commission_plan_type: CommissionPlanType|null, commission_override_mode: CommissionOverrideMode, deepest_manager_chain: int, commission_house_account: array{id: int, name: string, agents_under: int, earned_satang: int}|null}
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
             * How many managers a sale can have above it, at worst. Sent so
             * step 4 can show the arithmetic BEFORE somebody picks a deduct
             * mode — the owner's whole complaint was that the most
             * misunderstood number in the system had nothing on screen
             * explaining it. Zero for a company with no hierarchy yet, which
             * is a real and common answer, not a missing one.
             */
            'deepest_manager_chain' => $company === null ? 0 : $this->deductionGuard->deepestChain($company),
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
        ];
    }

    /**
     * @return array{id: int, name: string, agents_under: int, earned_satang: int}|null
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
     * @return array{commission_basis: CommissionBasis, commission_plan_type: CommissionPlanType|null, commission_override_mode: CommissionOverrideMode, deepest_manager_chain: int}
     */
    public function update(int $companyId, ?CommissionBasis $basis = null, ?CommissionPlanType $planType = null, ?CommissionOverrideMode $overrideMode = null, ?User $actor = null): array
    {
        $company = Company::findOrFail($companyId);

        if ($basis !== null) {
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
            $this->applyChange(
                $company,
                'commission_plan_type',
                $company->commission_plan_type?->value,
                $planType->value,
                'commission_plan_type.updated',
                $actor,
            );
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
