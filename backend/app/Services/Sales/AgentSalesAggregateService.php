<?php

namespace App\Services\Sales;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Services\Referral\ClosedDealPredicate;
use Illuminate\Support\Facades\DB;

/**
 * TASK-107 — the ONE per-agent sales/pipeline/money rollup, extracted from
 * SalesTeamOverviewService (TASK-050 / ADR-014) so the admin cockpit and the
 * Agent Portal team monitor cannot drift into two different answers to the
 * same question ("how many clients / deals / satang does this agent have").
 *
 * Extracted rather than copy-pasted deliberately: two implementations of the
 * same count would eventually disagree, and a leader seeing a different
 * number than their Company Admin sees for the same agent is a support
 * ticket at best and a trust problem at worst. SalesTeamOverviewService's
 * public output shape is unchanged — it still maps these values onto its own
 * key names (total_sales_satang / total_commission_satang) and still computes
 * its own `conversion`.
 *
 * SCOPING CONTRACT (BR-6 / §5): this Service does NOT tenant-filter. It
 * aggregates over exactly the agent ids it is handed, and every caller must
 * have derived those ids from a company-scoped source first —
 * SalesTeamOverviewService from its `where('company_id', …)` agent query,
 * TeamMonitorService from DownlineService (which filters every level of the
 * walk by the leader's company_id). Keeping the whitelist at the call site is
 * the same construction the original service already used and documented.
 *
 * Money is READ from existing rows, never recomputed (BR-4), and stays
 * integer satang end to end (BR-3).
 *
 * TASK-179 (human decisions D1/D2, 2026-08-13) — the two money figures on a
 * card answer two DIFFERENT questions and are read from two different tables.
 * Read this before changing either:
 *
 *   sales_satang                MONEY THE CUSTOMER PAID (D1) —
 *                               SUM(orders.amount_satang) over that agent's
 *                               PAID orders. It used to be the commission
 *                               ledger's `sale_price_satang_at_time`
 *                               snapshot, gated on `payment_status = paid`.
 *                               Three things were wrong with that and D2
 *                               rejected it BY NAME: (a) it is the
 *                               commission axis, not the sales axis, so a
 *                               customer who had paid in full contributed
 *                               ฿0 until the company ran payroll;
 *                               (b) the snapshot is absent for pre-TASK-047
 *                               rows; (c) it is absent ENTIRELY for a deal
 *                               closed while no commission rule was
 *                               configured, because
 *                               CommissionService::recordForReferral()
 *                               returns null and writes no row at all — and
 *                               COALESCE(...,0) turned every one of those
 *                               into a silent zero. This is the same
 *                               definition AgentDashboardMetricsService
 *                               uses for `sales_paid_satang`, so the two
 *                               screens can no longer show two different
 *                               "ยอดขาย" for one company.
 *   closed_deals_without_order  the disclosure that keeps the figure above
 *                               honest (D2) — this agent's closed deals
 *                               (ClosedDealPredicate) with NO paid order,
 *                               contributing zero baht. Never estimated,
 *                               never folded into the money total. The
 *                               per-agent twin of the dashboard's field of
 *                               the same name: the uncountable part is
 *                               DISCLOSED, and it has to be disclosed on
 *                               every screen that shows the total, not just
 *                               on the dashboard.
 *   commission_satang           unchanged — money the company DISBURSED to
 *                               this agent, paid ledger rows only (BR-4:
 *                               read, never recomputed).
 */
class AgentSalesAggregateService
{
    /**
     * Per-agent rollup keyed by agent id. Agents with no rows at all are
     * simply absent from the result — callers fill with self::emptyRow().
     *
     * @param  iterable<int|string>  $agentIds
     * @return array<int, array{client_count:int, deals_by_stage:array<string,int>, total_deals:int, closed_deals:int, closed_deals_without_order:int, sales_satang:int, commission_satang:int}>
     */
    public function forAgents(iterable $agentIds): array
    {
        $ids = collect($agentIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        // TASK-051 — commission the company has actually DISBURSED to this
        // agent: SUM(amount_satang) across ALL of their paid ledger rows
        // (direct + any override earned as an upline). Paid only, integer
        // satang (BR-3), READ from the immutable ledger — no calculation
        // Service is invoked here (BR-4).
        //
        // TASK-179 (D1/D2) — the SALES figure used to be computed in this
        // same query off `sale_price_satang_at_time`, and is not any more;
        // see $salesByAgent below and the class docblock for why.
        $commissionByAgent = DB::table('commission_ledger')
            ->whereIn('agent_id', $ids)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->selectRaw('agent_id, SUM(amount_satang) as total_commission_satang')
            ->groupBy('agent_id')
            ->pluck('total_commission_satang', 'agent_id');

        // TASK-179 §3.2 (D1/D2) — "ยอดขาย" is money the CUSTOMER paid, so it
        // comes from paid ORDERS, exactly as it does on the dashboard. Keyed
        // on orders.agent_id, which OrderService copies from the referral it
        // collects for, so per-agent figures still add up to the company
        // total the dashboard shows. Gated on the ORDER's status, never on
        // the commission's payment_status: whether payroll has run says
        // nothing about whether the customer paid.
        // orders.amount_satang is already integer satang (BR-3) — no
        // division, no round(), no float anywhere on this path.
        $salesByAgent = DB::table('orders')
            ->whereIn('agent_id', $ids)
            ->where('status', OrderStatus::Paid->value)
            ->selectRaw('agent_id, SUM(amount_satang) as total_sales_satang')
            ->groupBy('agent_id')
            ->pluck('total_sales_satang', 'agent_id');

        // deals per agent × stage
        $stageRows = DB::table('referrals')
            ->whereIn('agent_id', $ids)
            ->selectRaw('agent_id, current_stage, COUNT(*) as deal_count')
            ->groupBy('agent_id', 'current_stage')
            ->get();

        // distinct clients per agent (a client counted once even if it has
        // several referrals across different stages/products for this agent)
        $clientCounts = DB::table('referrals')
            ->whereIn('agent_id', $ids)
            ->selectRaw('agent_id, COUNT(DISTINCT client_id) as client_count')
            ->groupBy('agent_id')
            ->pluck('client_count', 'agent_id');

        // TASK-179 §3.1 / D4 — closed deals per agent, from the ONE shared
        // predicate (ClosedDealPredicate). It is deliberately its own query
        // rather than a sum over $stageRows above: "closed" means REACHED
        // Complete Payment, which a snapshot of current_stage cannot answer
        // once ADR-026 lets a template continue into จัดส่ง / นัดใช้บริการ /
        // ติดตามผล. The old closedFrom($stages) helper that lived here added
        // two hardcoded stage keys and was deleted with this change — a
        // second implementation is exactly how the funnel and the close rate
        // came to disagree (F-3).
        $closedQuery = DB::table('referrals')->whereIn('agent_id', $ids);
        ClosedDealPredicate::apply($closedQuery);
        $closedCounts = $closedQuery
            ->selectRaw('agent_id, COUNT(*) as closed_count')
            ->groupBy('agent_id')
            ->pluck('closed_count', 'agent_id');

        // TASK-179 §3.2 (D2) — the per-agent twin of the dashboard's
        // `closed_deals_without_order`: deals this agent HAS closed that
        // contribute zero baht to sales_satang because no paid order exists
        // for them. D2's whole point is that the uncountable part is
        // DISCLOSED rather than estimated, and a total is only as honest as
        // the screen it is shown on — so the disclosure travels with the
        // figure to every screen, not just to the dashboard.
        //
        // "No paid order" is asked of the REFERRAL (orders.referral_id), the
        // same way the dashboard asks it, so a deal is never called
        // uncounted while an order that really did pay sits against it.
        // Same ClosedDealPredicate as above — there is one definition of
        // "closed" in this codebase and this is not a second one.
        $withoutOrderQuery = DB::table('referrals')->whereIn('agent_id', $ids);
        ClosedDealPredicate::apply($withoutOrderQuery);
        $withoutOrderQuery->whereNotExists(function ($sub) {
            $sub->select(DB::raw('1'))
                ->from('orders')
                ->whereColumn('orders.referral_id', 'referrals.id')
                ->where('orders.status', OrderStatus::Paid->value);
        });
        $withoutOrderCounts = $withoutOrderQuery
            ->selectRaw('agent_id, COUNT(*) as without_order_count')
            ->groupBy('agent_id')
            ->pluck('without_order_count', 'agent_id');

        // pivot stageRows into [agent_id => [stage_key => count]]
        $byAgent = [];
        foreach ($stageRows as $row) {
            $byAgent[(int) $row->agent_id][$row->current_stage] = (int) $row->deal_count;
        }

        $result = [];
        foreach ($ids as $agentId) {
            $stages = [];
            $total = 0;
            foreach (self::stageKeys() as $key) {
                $count = $byAgent[$agentId][$key] ?? 0;
                $stages[$key] = $count;
                $total += $count;
            }

            $result[$agentId] = [
                'client_count' => (int) ($clientCounts[$agentId] ?? 0),
                'deals_by_stage' => $stages,
                'total_deals' => $total,
                'closed_deals' => (int) ($closedCounts[$agentId] ?? 0),
                'closed_deals_without_order' => (int) ($withoutOrderCounts[$agentId] ?? 0),
                'sales_satang' => (int) ($salesByAgent[$agentId] ?? 0),
                'commission_satang' => (int) ($commissionByAgent[$agentId] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * The zero row — an agent who exists but has never referred anyone. Kept
     * here (not inlined at each call site) so every consumer emits the same
     * key set, including every stage key, for an empty agent.
     *
     * @return array{client_count:int, deals_by_stage:array<string,int>, total_deals:int, closed_deals:int, closed_deals_without_order:int, sales_satang:int, commission_satang:int}
     */
    public static function emptyRow(): array
    {
        return [
            'client_count' => 0,
            'deals_by_stage' => array_fill_keys(self::stageKeys(), 0),
            'total_deals' => 0,
            'closed_deals' => 0,
            'closed_deals_without_order' => 0,
            'sales_satang' => 0,
            'commission_satang' => 0,
        ];
    }

    /**
     * The WHOLE §4.3 stage vocabulary (all PipelineStage cases — eight
     * since ADR-026 added the post-sale three, not five), always in
     * declaration order and always all present: a stage with no deals is 0,
     * never a missing key.
     *
     * TASK-179 §4.1 note for ag-ui: this is the full closed set, not a
     * per-referral journey. The consuming screens must render whatever keys
     * arrive, in the order they arrive — a fixed five-element array in Vue
     * is what made the funnel bars stop summing to the deal count (F-4).
     *
     * @return list<string>
     */
    public static function stageKeys(): array
    {
        return array_map(fn (PipelineStage $stage) => $stage->value, PipelineStage::cases());
    }
}
