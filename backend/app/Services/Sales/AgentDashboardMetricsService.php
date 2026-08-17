<?php

namespace App\Services\Sales;

use App\Enums\AgentApprovalStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Referral\ClosedDealPredicate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-052 / ADR-015 — read-only aggregation powering the chart-based
 * Agent Dashboard overview. One call returns every series the dashboard
 * needs: headline totals, a 6-month time series (customer sales, paid
 * commission, new agents), the §4.3 pipeline funnel, cert-tier + lead-
 * source distributions, and the top agents by paid commission.
 *
 * Never writes, never recomputes commission (BR-4 untouched) — it only
 * SUMs/COUNTs existing rows. Tenant-scoped (§5): every query is filtered
 * by the resolved company_id (the actor's own for a Company Admin; an
 * optional ?company_id for Super Admin, else all companies). Money stays
 * integer satang (BR-3); paid-only money is consistent with TASK-051.
 *
 * Month buckets are computed in PHP from fetched rows so the same code
 * works on MySQL (prod) and SQLite (tests) without per-driver date SQL.
 *
 * TASK-179 (human decisions D1–D4, 2026-08-13) — this class stopped
 * answering questions it was never asked. Read the field-by-field
 * contract below before changing anything here; every one of these was a
 * real number under a label that described a different quantity.
 *
 *   sales_paid_satang           MONEY THE CUSTOMER PAID (D1/D2) —
 *                               SUM(orders.amount_satang) over paid
 *                               orders. It was the commission ledger's
 *                               sale-price snapshot, which is (a) a
 *                               different axis from "ยอดขาย" and (b)
 *                               absent entirely for any deal closed while
 *                               no commission rule was configured.
 *   closed_deals_without_order  the disclosure that makes the figure
 *                               above honest — closed deals contributing
 *                               ZERO baht because they have no paid
 *                               order. Never estimated, never folded in.
 *   deals_closed                REACHED Complete Payment, post-sale
 *                               stages included (D4) — see
 *                               ClosedDealPredicate, which is the same
 *                               predicate AgentSalesAggregateService
 *                               uses. It was a hardcoded two-stage list.
 *   monthly[].sales_satang      bucketed on the SALE date (D3), i.e. the
 *                               order's paid_at, not the commission
 *                               disbursement date.
 *   agents_total                ACTIVE agents only (§3.5) — it used to
 *                               include soft-deleted ones while
 *                               SalesTeamOverviewService did not, under
 *                               the identical label "ตัวแทนทั้งหมด".
 *   agents_pending              every user awaiting approval REGARDLESS
 *                               OF ROLE (§3.4) — exactly the set
 *                               GET /agent-approvals paginates. It
 *                               counted role=agent only, so a pending
 *                               Company Admin appeared in the list and
 *                               not in the KPI beside it.
 *   cert_tier_distribution      one agent, ONE slice: their HIGHEST
 *                               passed tier (§3.8) — see certDistribution().
 */
class AgentDashboardMetricsService
{
    private const MONTHS = 6;

    /**
     * @return array<string, mixed>
     */
    public function build(User $actor, ?int $companyId = null): array
    {
        $scopedCompanyId = $actor->isSuperAdmin() ? $companyId : $actor->company_id;

        $agentsQuery = User::query()->where('role', UserRole::Agent->value);
        if ($scopedCompanyId !== null) {
            $agentsQuery->where('company_id', $scopedCompanyId);
        }
        // NB: there is NO users.is_active column — "active" means
        // not-soft-deleted (UserResource derives is_active = deleted_at is
        // null). So $agents (default query excludes trashed) is already the
        // ACTIVE agents; inactive = onlyTrashed(), counted in totals().
        $agents = $agentsQuery->get(['id', 'name', 'avatar_path', 'agent_approval_status', 'created_at']);
        $agentIds = $agents->pluck('id');

        return [
            'totals' => $this->totals($scopedCompanyId, $agents, $agentIds),
            'monthly' => $this->monthly($scopedCompanyId, $agents),
            'deals_by_stage' => $this->dealsByStage($scopedCompanyId),
            'cert_tier_distribution' => $this->certDistribution($agentIds),
            'lead_source_distribution' => $this->leadSourceDistribution($scopedCompanyId),
            'top_agents' => $this->topAgents($scopedCompanyId, $agents),
        ];
    }

    /**
     * @param  Collection<int, User>  $agents
     * @param  Collection<int, int>  $agentIds
     * @return array<string, mixed>
     */
    private function totals($companyId, $agents, $agentIds): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();

        $referrals = DB::table('referrals');
        $this->scope($referrals, $companyId);
        $dealsTotal = (int) (clone $referrals)->count();

        // D4 / §3.1 — REACHED Complete Payment, post-sale stages included.
        // Same predicate AgentSalesAggregateService uses; there is exactly
        // one implementation of "closed" in this codebase now.
        $closedQuery = clone $referrals;
        ClosedDealPredicate::apply($closedQuery);
        $dealsClosed = (int) $closedQuery->count();

        // §3.2 — the disclosure that keeps sales_paid_satang honest (D2):
        // deals that ARE closed but contribute zero baht because no paid
        // order exists for them. Deliberately its own field, never folded
        // into the money total and never used to estimate one.
        $closedWithoutOrder = clone $referrals;
        ClosedDealPredicate::apply($closedWithoutOrder);
        $closedWithoutOrder->whereNotExists(function ($sub) {
            $sub->select(DB::raw('1'))
                ->from('orders')
                ->whereColumn('orders.referral_id', 'referrals.id')
                ->where('orders.status', OrderStatus::Paid->value);
        });
        $closedDealsWithoutOrder = (int) $closedWithoutOrder->count();

        $clients = DB::table('clients');
        $this->scope($clients, $companyId);

        // D1/D2 — "ยอดขาย" is money the CUSTOMER paid, read from paid
        // orders. NOT the ledger's sale-price snapshot, which is a
        // commission-side field: it is missing for pre-TASK-047 rows and
        // absent entirely whenever CommissionService::recordForReferral()
        // found no configured rule and wrote no ledger row at all. BR-3:
        // orders.amount_satang is already integer satang.
        $ordersPaid = DB::table('orders')->where('status', OrderStatus::Paid->value);
        $this->scope($ordersPaid, $companyId);
        $salesPaid = (int) $ordersPaid->sum('amount_satang');

        $ledgerPaid = DB::table('commission_ledger')->where('payment_status', PaymentStatus::Paid->value);
        $this->scope($ledgerPaid, $companyId);
        $commissionPaid = (int) $ledgerPaid->sum('amount_satang');

        $ledgerPending = DB::table('commission_ledger')->where('payment_status', PaymentStatus::Pending->value);
        $this->scope($ledgerPending, $companyId);
        $commissionPending = (int) $ledgerPending->sum('amount_satang');

        $certPassed = DB::table('user_certifications')->whereIn('user_id', $agentIds)->distinct()->count('user_id');

        // "Active" = not soft-deleted → $agents already holds only those.
        // "Inactive" = soft-deleted agents (onlyTrashed), counted separately.
        $activeCount = $agents->count();
        $inactiveQuery = User::onlyTrashed()->where('role', UserRole::Agent->value);
        if ($companyId !== null) {
            $inactiveQuery->where('company_id', $companyId);
        }
        $inactiveCount = $inactiveQuery->count();

        // §3.4 (F-7) — count EXACTLY what GET /agent-approvals lists:
        // every user whose agent_approval_status is pending, with NO role
        // filter and excluding soft-deleted rows (the endpoint's own
        // User::query() does the same). It used to filter role=agent, so a
        // pending Company Admin showed up in the list and not in the KPI
        // sitting next to it. The endpoint is unchanged; the KPI moved to
        // meet it. UI label must therefore say "ผู้ใช้ที่รออนุมัติ", not
        // "ตัวแทนที่รออนุมัติ".
        $pendingQuery = User::query()->where('agent_approval_status', AgentApprovalStatus::Pending->value);
        if ($companyId !== null) {
            $pendingQuery->where('company_id', $companyId);
        }
        $pendingCount = $pendingQuery->count();

        return [
            // §3.5 (F-8) — ACTIVE agents, deactivated excluded, which is
            // what SalesTeamOverviewService has always counted under the
            // same "ตัวแทนทั้งหมด" label. The deactivated ones remain
            // available as their own, separately-labelled field below.
            'agents_total' => $activeCount,
            'agents_active' => $activeCount,
            'agents_inactive' => $inactiveCount,
            'agents_pending' => $pendingCount,
            'new_agents_this_month' => $agents->filter(fn ($a) => $a->created_at !== null && $a->created_at->greaterThanOrEqualTo($startOfMonth))->count(),
            'cert_passed' => (int) $certPassed,
            'cert_pending' => max(0, $activeCount - (int) $certPassed),
            'clients_total' => (int) $clients->count(),
            'deals_total' => $dealsTotal,
            'deals_closed' => $dealsClosed,
            'conversion' => $dealsTotal > 0 ? round($dealsClosed / $dealsTotal * 100, 1) : 0.0,
            'sales_paid_satang' => $salesPaid,
            'closed_deals_without_order' => $closedDealsWithoutOrder,
            'commission_paid_satang' => $commissionPaid,
            'commission_pending_satang' => $commissionPending,
        ];
    }

    /**
     * 6-month series of customer sales, paid commission and new agents.
     *
     * §3.3 (D3, F-6) — the two money series are now on the SAME time axis:
     * both are bucketed by when the event they describe happened.
     * `sales_satang` buckets on the paid ORDER's paid_at (when the customer
     * paid); `commission_satang` buckets on commission_ledger.paid_at (when
     * the company disbursed). That was NOT previously true: sales_satang
     * was read off the ledger row too, so a January sale whose commission
     * was disbursed in March was plotted in March, and the chart's two
     * lines silently described the same event twice under two labels.
     *
     * A paid order with a NULL paid_at cannot be placed on a time axis at
     * all, so it is absent from this series while still counting in
     * totals.sales_paid_satang. OrderService always stamps paid_at when it
     * confirms a payment, so this is a hand-edited-row guard, not a normal
     * case — and dropping such a row here is preferable to inventing a
     * month for it.
     *
     * @param  Collection<int, User>  $agents
     * @return list<array<string, mixed>>
     */
    private function monthly($companyId, $agents): array
    {
        // Build the 6 month keys (oldest → current).
        $months = [];
        for ($i = self::MONTHS - 1; $i >= 0; $i--) {
            $months[Carbon::now()->startOfMonth()->subMonths($i)->format('Y-m')] = [
                'sales_satang' => 0,
                'commission_satang' => 0,
                'new_agents' => 0,
            ];
        }
        $earliest = Carbon::now()->startOfMonth()->subMonths(self::MONTHS - 1);

        // Customer money, on the SALE date (D3): the paid order's paid_at.
        $orders = DB::table('orders')
            ->where('status', OrderStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $earliest);
        $this->scope($orders, $companyId);
        foreach ($orders->get(['paid_at', 'amount_satang']) as $row) {
            $key = Carbon::parse($row->paid_at)->format('Y-m');
            if (! isset($months[$key])) {
                continue;
            }
            $months[$key]['sales_satang'] += (int) $row->amount_satang;
        }

        // Commission disbursed, on the DISBURSEMENT date — a separate
        // series answering a separate question (BR-4: read, never recompute).
        $ledger = DB::table('commission_ledger')
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $earliest);
        $this->scope($ledger, $companyId);
        foreach ($ledger->get(['paid_at', 'amount_satang']) as $row) {
            $key = Carbon::parse($row->paid_at)->format('Y-m');
            if (! isset($months[$key])) {
                continue;
            }
            $months[$key]['commission_satang'] += (int) $row->amount_satang;
        }

        // New agents per month from the already-loaded agent collection.
        foreach ($agents as $a) {
            if ($a->created_at === null) {
                continue;
            }
            $key = $a->created_at->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['new_agents']++;
            }
        }

        $out = [];
        foreach ($months as $key => $vals) {
            $out[] = ['month' => $key] + $vals;
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function dealsByStage($companyId): array
    {
        $q = DB::table('referrals');
        $this->scope($q, $companyId);
        $counts = $q->selectRaw('current_stage, COUNT(*) as c')->groupBy('current_stage')->pluck('c', 'current_stage');

        $out = [];
        foreach (PipelineStage::cases() as $stage) {
            $out[$stage->value] = (int) ($counts[$stage->value] ?? 0);
        }

        return $out;
    }

    /**
     * §3.8 (F-5) — CHOICE MADE: each agent contributes to exactly ONE
     * slice, their HIGHEST passed tier (cert_tiers.sort_order DESC, the
     * same ranking BR-2 and User::highestPassedCertTier() use).
     *
     * Before this, an agent holding Basic AND Intermediate was counted in
     * both slices, so the slices summed to more than the workforce and the
     * percentages were shares of nothing. They are now a partition of the
     * CERTIFIED agents: sum(count) === totals.cert_passed, and the
     * uncertified remainder is totals.cert_pending. The UI must label the
     * donut accordingly and must not present it as a share of all agents
     * without adding that remainder itself.
     *
     * Bulk-loaded and deduplicated in PHP (same shape
     * AgentCommissionSummaryService already uses for its per-agent highest
     * tier) rather than a per-agent query.
     *
     * @param  Collection<int, int>  $agentIds
     * @return list<array<string, mixed>>
     */
    private function certDistribution($agentIds): array
    {
        if ($agentIds->isEmpty()) {
            return [];
        }

        // `key` is a reserved word in MySQL — let the query builder wrap the
        // identifiers (per-driver backticking) via select() rather than a
        // raw string, so it's safe on MySQL and SQLite alike.
        return DB::table('user_certifications')
            ->join('cert_tiers', 'cert_tiers.id', '=', 'user_certifications.cert_tier_id')
            ->whereIn('user_certifications.user_id', $agentIds)
            ->orderByDesc('cert_tiers.sort_order')
            ->get(['user_certifications.user_id', 'cert_tiers.key', 'cert_tiers.name', 'cert_tiers.sort_order'])
            // highest first + keep-first-occurrence = the agent's top tier
            ->unique('user_id')
            ->groupBy('key')
            ->map(fn ($rows) => [
                'key' => $rows->first()->key,
                'name' => $rows->first()->name,
                'sort_order' => (int) $rows->first()->sort_order,
                'count' => $rows->count(),
            ])
            ->sortBy('sort_order')
            ->values()
            ->map(fn (array $r) => ['key' => $r['key'], 'name' => $r['name'], 'count' => $r['count']])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leadSourceDistribution($companyId): array
    {
        $q = DB::table('clients');
        $this->scope($q, $companyId);

        return $q->selectRaw('lead_source, COUNT(*) as count')
            ->groupBy('lead_source')
            ->get()
            ->map(fn ($r) => ['source' => $r->lead_source ?: 'ไม่ระบุ', 'count' => (int) $r->count])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Top 5 agents by paid commission.
     *
     * @param  Collection<int, User>  $agents
     * @return list<array<string, mixed>>
     */
    private function topAgents($companyId, $agents): array
    {
        $byId = $agents->keyBy('id');

        $q = DB::table('commission_ledger')
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereIn('agent_id', $agents->pluck('id'));
        $this->scope($q, $companyId);

        return $q->selectRaw('agent_id, SUM(amount_satang) as commission_satang')
            ->groupBy('agent_id')
            ->orderByDesc('commission_satang')
            ->limit(5)
            ->get()
            ->map(function ($r) use ($byId) {
                $agent = $byId->get($r->agent_id);

                return [
                    'agent_id' => (int) $r->agent_id,
                    'name' => $agent?->name,
                    'avatar_url' => $agent?->avatar_path ? Storage::disk('public')->url($agent->avatar_path) : null,
                    'commission_satang' => (int) $r->commission_satang,
                ];
            })
            ->all();
    }

    /**
     * Apply the tenant company_id filter to a query builder (no-op for a
     * Super Admin with no company selected — they see all companies).
     */
    private function scope($query, ?int $companyId): void
    {
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }
    }
}
