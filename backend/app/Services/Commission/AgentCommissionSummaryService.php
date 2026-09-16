<?php

namespace App\Services\Commission;

use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-043 §3 — "ค่าคอมมิชชั่น" submenu under "จัดการตัวแทน": one row per
 * agent (total_paid_satang / total_pending_satang / entry_count). Pure
 * read/aggregation over existing commission_ledger rows — BR-4's "never
 * recompute commission live" is about the AMOUNT (rate x package),
 * which is never touched here; this only SUMs already-written, already
 * immutable rows, same "live aggregation of immutable rows is fine"
 * reasoning as any other historical report in this codebase (see
 * ProductGradingService for the same shape on a different table).
 *
 * Deliberately a standalone lightweight Service rather than a new
 * method on CommissionService: CommissionService owns commission
 * CALCULATION (writing ledger rows via recordForReferral() and its
 * plan-type sub-services, see that class's own docblock) — this class
 * owns REPORTING (grouping existing rows), the same separation
 * ConfigHealthReportService/PlatformReportService keep from the
 * Services whose output they report on.
 *
 * Tenant scoping: CommissionLedger already carries a TenantScope
 * global scope (Section 5 rule 2), so a Company Admin's query is
 * auto-narrowed to their own company_id with no manual `where` needed.
 * $companyId is still threaded through explicitly here (never trusted
 * from the client — only ever populated by the Controller from a
 * confirmed Super Admin's own request) for the same two reasons the
 * ConfigHealthReportService/ProductGradingService precedents do it:
 * (1) belt-and-suspenders defensive scoping for a money-adjacent
 * report, not solely relying on a global scope that resolves off
 * auth()->user() internally, and (2) it's the only way to give Super
 * Admin an explicit ?company_id= narrowing, since TenantScope exempts
 * Super Admin entirely.
 *
 * TASK-044 §2 — $dateFrom/$dateTo/$paymentStatus are additive filters,
 * layered on top of the tenant scoping above, never replacing it. All
 * three are plain optional scalar/enum method parameters (not a
 * request/DTO object) specifically so the not-yet-built CSV export
 * Controller (Phase A item 3, TASK-044) can call buildSummary()
 * directly with its own already-validated values, the same way this
 * Controller does, without constructing anything Http-specific.
 *
 * $dateFrom/$dateTo filter on commission_ledger.created_at (when the
 * ledger row was written — always populated, unlike paid_at which is
 * null for pending rows) — see TASK-044 spec for why created_at was
 * chosen as the date axis over paid_at.
 *
 * $paymentStatus, when given, is applied as a `where` before
 * aggregation so only matching rows are summed/counted at all — see
 * the inline comment at the SELECT below for why this also simplifies
 * away the CASE WHEN split rather than keeping it and filtering after.
 */
class AgentCommissionSummaryService
{
    /**
     * How many unpaid sales each row carries — see pendingItemsFor().
     *
     * Three fits the two lines the payout row has for it. Raising this is a
     * decision about the SCREEN, so it lives here where the query that honours
     * it is, and not as a literal buried in a loop.
     */
    private const PENDING_ITEMS_PER_ROW = 3;

    /**
     * TASK-179 §3.7 (F-10) — NULL, NOT ZERO, for a bucket the filter
     * excluded.
     *
     * When $paymentStatus narrows the rows before aggregation, the OTHER
     * bucket has not been measured at all. It used to be reported as
     * literal 0, so filtering by "จ่ายแล้ว" rendered "รอจ่ายรวม 0 บาท" —
     * visually identical to "we owe our agents nothing", which is a
     * statement about money that nobody computed. `null` is the only
     * honest value: it forces the UI to say "ไม่ได้แสดง" rather than print
     * a number. Callers MUST NOT `?? 0` it back at the display layer.
     *
     * The CSV payout export is unaffected: it forces
     * paymentStatus = Pending and reads only total_pending_satang, which is
     * the measured side of that call.
     *
     * @return Collection<int, array{agent_id: int, agent_name: ?string, total_paid_satang: ?int, total_pending_satang: ?int, entry_count: int, bank_name: ?string, bank_account_number: ?string, bank_account_holder_name: ?string, avatar_url: ?string, cert_tier: ?array{id: int, key: string, name: string}, is_company_share: bool}>
     */
    public function buildSummary(
        User $actor,
        ?int $companyId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?PaymentStatus $paymentStatus = null,
    ): Collection {
        $query = $this->scopedLedger($actor, $companyId, $dateFrom, $dateTo);

        // TASK-044 §2 — when a status filter is given, narrow the rows
        // BEFORE aggregation rather than aggregating everything and
        // discarding half the CASE WHEN split: every row reaching the
        // SUM below already matches $paymentStatus, so a single
        // SUM(amount_satang) is both correct and simpler than the
        // unfiltered branch's CASE WHEN. See the map() below for how
        // the single sum is placed back into the still-two-field
        // (total_paid_satang/total_pending_satang) response shape.
        if ($paymentStatus !== null) {
            $query->where('payment_status', $paymentStatus->value);
        }

        $query = $paymentStatus !== null
            ? $query->selectRaw('agent_id, SUM(amount_satang) as filtered_total_satang, COUNT(*) as entry_count')
            : $query->selectRaw(
                'agent_id, '.
                'SUM(CASE WHEN payment_status = ? THEN amount_satang ELSE 0 END) as total_paid_satang, '.
                'SUM(CASE WHEN payment_status = ? THEN amount_satang ELSE 0 END) as total_pending_satang, '.
                'COUNT(*) as entry_count',
                [PaymentStatus::Paid->value, PaymentStatus::Pending->value]
            );

        $rows = $query
            ->groupBy('agent_id')
            // TASK-045 — Admin asked to fill in an agent's bank account
            // directly from this commission screen (previously only
            // possible from the "จัดการตัวแทน" agent list). The Admin
            // needs to SEE the current (masked) value to decide whether
            // it needs updating, same as AgentManagementView already
            // shows — so bank_name/bank_account_number/
            // bank_account_holder_name are eager-loaded here too. This
            // is an additive field on the existing response shape, safe
            // for the CSV export caller (which does its own separate
            // unmasked User query and never reads these masked fields).
            // TASK-047 (follow-up) — avatar_path added so the row itself
            // can show a real avatar (or tier-colored initial-circle)
            // without the Admin having to open "ดูรายละเอียด" first —
            // human feedback: "รูป avatar ให้ขึ้นที่รายชื่อเลยไม่ต้องคลิ๊กดู
            // รายละเอียด".
            /*
             * 2026-09-15 (ครั้งที่สอง) — four more columns and one more
             * relation, all of them so `payout_details_complete` below can be
             * answered by the model method that actually refuses the payout
             * rather than by a second copy of the rule written here.
             *
             * national_id / id_document_type ARE the first half of that rule.
             * A narrower select leaves them null on a hydrated model, and
             * `filled(null)` is false — so every agent on the screen would
             * have been reported as unpayable, with no query failing and
             * nothing to see.
             *
             * company_id + the company relation are the other half: the rule
             * differs for the company's own seat, and
             * User::isCommissionHouseAccount() falls back to its own SELECT
             * when the relation is not loaded — one per row, on a list.
             */
            ->with([
                'agent:id,name,company_id,avatar_path,national_id,id_document_type,bank_name,bank_account_number,bank_account_holder_name',
                'agent.company:id,commission_house_user_id',
            ])
            ->get();

        // TASK-047 (follow-up) — bulk-load each agent's HIGHEST passed
        // cert tier (BR-2's ranking) in ONE query rather than calling
        // User::highestPassedCertTier() per row in the map() below (which
        // would be an N+1 query per agent on this list). Ties/multiple
        // passed tiers per agent are resolved by ordering
        // cert_tiers.sort_order DESC and keeping only the first row per
        // agent_id (->unique('user_id') keeps the first occurrence).
        /*
         * 2026-09-15 — which of these payees are companies rather than
         * people. One query for the page; ids are unique across tenants, so
         * a flat list is exact and reveals nothing — it only labels rows
         * this method was already returning.
         */
        $houseUserIds = Company::withTrashed()
            ->whereNotNull('commission_house_user_id')
            ->pluck('commission_house_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        /*
         * ═══ 2026-09-16 — HOW MUCH OF EACH BALANCE IS ALREADY SPOKEN FOR ═══
         *
         * Owner: "ผมกดยืนยันการจ่ายไปแล้ว แต่ปัญหาคือหน้าจอ Ui ยังขึ้นค้างจ่ายอยู่".
         *
         * They were looking at a real defect, and it was a dead end rather than
         * a cosmetic one. Two different numbers were both being called "ยอด
         * ค้างจ่าย":
         *
         *   · THIS service summed pending ledger rows — 1,046.50
         *   · CommissionWithdrawalService::availableSatang() subtracts whatever
         *     an open payout has already reserved — 0.00 once one is raised
         *
         * แนวทาง C does not settle the ledger when a payout is raised (the
         * money has not moved yet), so after a successful ตั้งจ่าย this screen
         * showed the row completely unchanged, still ticked and still
         * selectable — and every further press was refused forever with "ยอด
         * ค้างจ่ายของคนนี้เปลี่ยนไปแล้ว (0.00 ไม่ตรงกับ 1,046.50)". The screen
         * was inviting an action that could only fail.
         *
         * One query for the page, keyed by agent. The screen now shows what is
         * owed AND what is already in flight, and sends the difference as the
         * figure it is agreeing to.
         */
        $reservedByAgentId = DB::table('commission_withdrawal_items')
            ->join(
                'commission_withdrawal_requests',
                'commission_withdrawal_requests.id',
                '=',
                'commission_withdrawal_items.commission_withdrawal_request_id',
            )
            ->whereIn('commission_withdrawal_requests.agent_id', $rows->pluck('agent_id'))
            ->whereIn(
                'commission_withdrawal_requests.status',
                array_column(WithdrawalStatus::open(), 'value'),
            )
            ->groupBy('commission_withdrawal_requests.agent_id')
            ->selectRaw('commission_withdrawal_requests.agent_id AS agent_id, SUM(commission_withdrawal_items.allocated_satang) AS reserved_satang')
            ->pluck('reserved_satang', 'agent_id');

        /*
         * ═══ 2026-09-17 — WHAT WAS SOLD, ON THE ROW ITSELF ═══
         *
         * Owner: "ให้แสดงสินค้าและราคาขาย ในช่องแรกเลย".
         *
         * The screen said "1 รายการ" and nothing else. Whoever is about to
         * transfer money to this person could see the amount and not what it
         * was for without opening the drill-down — one press per payee, on the
         * screen whose whole job is to decide a payout round at a glance.
         *
         * PENDING ROWS ONLY, and named so. This list answers "what is this
         * unpaid balance made of", which is the question step ① asks; folding
         * settled sales into it would put products on the row that the amount
         * beside them does not include.
         *
         * Scoped by re-deriving the same company and date filters rather than
         * reusing $query: that builder has already had GROUP BY and a raw
         * aggregate select applied to it, and cloning it would return sums, not
         * rows. Two places applying one scope is a risk, so it is applied by
         * the same private method both times.
         */
        $itemsByAgentId = $this->pendingItemsFor(
            $rows->pluck('agent_id')->all(),
            $actor,
            $companyId,
            $dateFrom,
            $dateTo,
        );

        $certTiersByAgentId = DB::table('user_certifications')
            ->join('cert_tiers', 'cert_tiers.id', '=', 'user_certifications.cert_tier_id')
            ->whereIn('user_certifications.user_id', $rows->pluck('agent_id'))
            ->orderByDesc('cert_tiers.sort_order')
            ->get(['user_certifications.user_id', 'cert_tiers.id', 'cert_tiers.key', 'cert_tiers.name'])
            ->unique('user_id')
            ->keyBy('user_id');

        return $rows
            ->map(function (CommissionLedger $row) use ($paymentStatus, $certTiersByAgentId, $houseUserIds, $reservedByAgentId, $itemsByAgentId) {
                // BR-3 — SUM() over an already-integer satang column is
                // still integer arithmetic; cast defensively since raw
                // SQL aggregates come back as strings from the PDO
                // driver, never a float.
                if ($paymentStatus !== null) {
                    // §3.7 — the excluded bucket is null ("not measured"),
                    // never 0 ("measured, and it is nothing").
                    $amount = (int) $row->filtered_total_satang;
                    $totalPaid = $paymentStatus === PaymentStatus::Paid ? $amount : null;
                    $totalPending = $paymentStatus === PaymentStatus::Pending ? $amount : null;
                } else {
                    $totalPaid = (int) $row->total_paid_satang;
                    $totalPending = (int) $row->total_pending_satang;
                }

                return [
                    'agent_id' => (int) $row->agent_id,
                    'agent_name' => $row->agent?->name,
                    'total_paid_satang' => $totalPaid,
                    'total_pending_satang' => $totalPending,
                    'entry_count' => (int) $row->entry_count,
                    // TASK-047 — human-confirmed reversal of TASK-045's
                    // masking here ("แสดงเลยครับ เพราะต้องใช้งาน" — show it
                    // directly, it's needed for actual use; a hide/show
                    // toggle is explicitly deferred to a future
                    // system-settings task, not built now). Safe to return
                    // the REAL number unmasked: every caller of
                    // buildSummary() is already gated to Company
                    // Admin/Super Admin only (AgentCommissionSummaryController
                    // ::index() aborts 403 for any other role) AND scoped to
                    // the actor's own company_id above — an Agent can never
                    // reach this method at all, so this is not the same
                    // "list/summary response must never leak the full
                    // number" surface UserResource's default masking guards
                    // (that default is unchanged for Agent-reachable
                    // endpoints — see UserResource's own docblock).
                    'bank_name' => $row->agent?->bank_name,
                    'bank_account_number' => $row->agent?->bank_account_number,
                    'bank_account_holder_name' => $row->agent?->bank_account_holder_name,
                    // TASK-047 (follow-up) — real avatar (Storage::url(),
                    // same pattern UserResource already uses) or null so
                    // the frontend falls back to a colored initial-circle
                    // — never fabricate a placeholder image URL.
                    'avatar_url' => $row->agent?->avatar_path
                        ? Storage::disk('public')->url($row->agent->avatar_path)
                        : null,
                    // Null when the agent hasn't passed any cert tier yet
                    // — frontend must render a neutral default, never
                    // invent a tier (CLAUDE.md §8 guardrail #2).
                    'cert_tier' => ($tier = $certTiersByAgentId->get($row->agent_id))
                        ? ['id' => $tier->id, 'key' => $tier->key, 'name' => $tier->name]
                        : null,
                    /*
                     * 2026-09-15 — THE COMPANY'S OWN SHARE, NOT AN AGENT'S.
                     *
                     * A company can hold a seat at the top of its hierarchy
                     * and be paid a leader's override
                     * (CommissionHouseAccountService), and this summary groups
                     * by agent_id — so that seat appears here as a payee among
                     * the people.
                     *
                     * It was flagged here so the screen could keep it OUT of
                     * the payout run. The owner has since asked for the
                     * opposite ("ให้เพิ่มทำจ่ายบริษัทให้เลือกได้ด้วย"), so the flag
                     * now only changes how the row is LABELLED and where its
                     * bank details are edited — ตั้งค่าค่าแนะนำ rather than the
                     * agent's own profile, because that row is not a person.
                     */
                    'is_company_share' => in_array((int) $row->agent_id, $houseUserIds, true),
                    /*
                     * Can this payee be paid at all, right now?
                     *
                     * The same method that refuses in
                     * CommissionWithdrawalService, asked here so the screen can
                     * say why a row cannot be selected BEFORE somebody selects
                     * it and presses. Re-deriving the rule in the browser is
                     * how the two answers start to disagree — and the rule
                     * differs per payee type (the company seat has no identity
                     * document and never will).
                     */
                    'payout_details_complete' => (bool) $row->agent?->hasCompletePayoutDetails(),
                    /*
                     * Already reserved by a payout that has been raised and not
                     * yet transferred. The ledger rows are still Pending — the
                     * money has not moved — so this is NOT subtracted from
                     * total_pending_satang above, which remains the honest
                     * answer to "what is this person owed".
                     */
                    'reserved_satang' => (int) ($reservedByAgentId[$row->agent_id] ?? 0),
                    /*
                     * What a NEW payout may be raised for — the figure the
                     * server compares the press against
                     * (CommissionWithdrawalService::availableSatang), so the
                     * screen sends this one and the two can never disagree.
                     *
                     * NULL when the pending bucket was filtered out: an
                     * available balance derived from an unmeasured one is
                     * itself unmeasured, and a 0 here would grey out a row for
                     * a reason that is not true (§3.7 / F-10).
                     */
                    'available_satang' => $totalPending === null
                        ? null
                        : max(0, $totalPending - (int) ($reservedByAgentId[$row->agent_id] ?? 0)),
                    /*
                     * 2026-09-17 — the products behind the UNPAID balance, so
                     * the row says what the money is for.
                     *
                     * Capped (see pendingItemsFor). `entry_count` above is the
                     * honest total, so a screen showing three of eleven can say
                     * so rather than implying it has them all.
                     */
                    'pending_items' => $itemsByAgentId[(int) $row->agent_id] ?? [],
                ];
            })
            // ?? 0 here is an ORDERING fallback only — it never reaches the
            // response, where the excluded bucket must stay null (§3.7).
            ->sortByDesc(fn (array $row) => ($row['total_paid_satang'] ?? 0) + ($row['total_pending_satang'] ?? 0))
            ->values();
    }

    /**
     * The tenant and date scope every read in this service starts from.
     *
     * Extracted 2026-09-17 when a second query needed it. BR-6 is not a filter
     * you write twice: a Company Admin is hard-scoped to their own company here
     * and a client-supplied company_id is never trusted for that role, and the
     * one place both readers get that from is this method.
     *
     * @return Builder<CommissionLedger>
     */
    private function scopedLedger(
        User $actor,
        ?int $companyId,
        ?string $dateFrom,
        ?string $dateTo,
    ): Builder {
        $query = CommissionLedger::query();

        if (! $actor->isSuperAdmin()) {
            // Company Admin — hard-scoped to their own company (BR-6), never
            // trust a client-supplied company_id for this role.
            $query->where('company_id', $actor->company_id);
        } elseif ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        // TASK-044 §2 — additive date-range filter on created_at, on top of the
        // tenant scoping above (never a substitute for it).
        if ($dateFrom !== null) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * 2026-09-17 — the unpaid sales behind each payee's balance.
     *
     * Owner: "ให้แสดงสินค้าและราคาขาย ในช่องแรกเลย".
     *
     * ── WHY IT IS CAPPED ──
     *
     * A payee with forty unpaid entries would otherwise ship forty product
     * names to a screen that has room for two lines, on every row of the list.
     * Three is what fits; `entry_count` is already on the row, so the screen can
     * say "และอีก 37 รายการ" truthfully rather than implying it has them all.
     *
     * ── WHY NEWEST FIRST ──
     *
     * Not "biggest first". An admin scanning this row is checking that the
     * balance looks like recent activity they recognise; ordering by amount
     * would hide today's sale behind a large one from March.
     *
     * @param  array<int, mixed>  $agentIds
     * @return array<int, array<int, array{product_name: string|null, sale_price_satang: int|null, amount_satang: int}>>
     */
    private function pendingItemsFor(
        array $agentIds,
        User $actor,
        ?int $companyId,
        ?string $dateFrom,
        ?string $dateTo,
    ): array {
        if ($agentIds === []) {
            return [];
        }

        $rows = $this->scopedLedger($actor, $companyId, $dateFrom, $dateTo)
            ->where('payment_status', PaymentStatus::Pending->value)
            ->whereIn('agent_id', $agentIds)
            ->with('product:id,name')
            ->latest('id')
            ->get(['id', 'agent_id', 'product_id', 'sale_price_satang_at_time', 'amount_satang']);

        $byAgent = [];

        foreach ($rows as $row) {
            $agentId = (int) $row->agent_id;

            // Capped HERE rather than with a per-agent LIMIT in SQL: one query
            // for the page beats one per payee, and a list this size is cheaper
            // to trim in PHP than to window in a portable way (production is
            // MySQL, the test suite is SQLite).
            if (count($byAgent[$agentId] ?? []) >= self::PENDING_ITEMS_PER_ROW) {
                continue;
            }

            $byAgent[$agentId][] = [
                'product_name' => $row->product?->name,
                // Null is a real answer: rows written before the snapshot column
                // existed have no price, and inventing one would be a business
                // value nobody recorded (BR-7).
                'sale_price_satang' => $row->sale_price_satang_at_time === null
                    ? null
                    : (int) $row->sale_price_satang_at_time,
                'amount_satang' => (int) $row->amount_satang,
            ];
        }

        return $byAgent;
    }
}
