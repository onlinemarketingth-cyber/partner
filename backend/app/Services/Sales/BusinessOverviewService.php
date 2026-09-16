<?php

namespace App\Services\Sales;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ภาพรวมธุรกิจ — the numbers somebody runs the company on. 2026-09-16.
 *
 * Owner: "ที่ผมว่ามันยังขาดจริง คือหน้าที่ให้ทีมบริหารค่าคอมได้ ตัวเลขที่จำเป็น
 * ต่างๆ สำหรับผู้บริหารในการบริหารการเงิน ยอดขาย สินค้าขายดีต่างๆ".
 *
 * A survey of what already existed found most of the inputs present and spread
 * across five screens, two of which computed the same figure in different
 * ways. This service is the one place they are assembled, and the reason it
 * exists rather than a sixth screen stitching existing endpoints together is
 * everything below.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * ONE DATE AXIS: orders.paid_at.
 *
 * The four reports that existed before this one bucketed on four different
 * columns — orders.paid_at, commission_ledger.paid_at,
 * commission_ledger.created_at and referrals.submitted_at — so "เดือนกันยายน"
 * had four answers depending on which screen was open, and nothing said so.
 *
 * Here, the window means ONE thing: money that arrived between these two
 * dates. Every figure in the response is filtered on that same axis, including
 * the commission and the cost, which are the commission and cost OF THOSE
 * SALES rather than of whatever was disbursed or invoiced in the same weeks.
 * That is what makes the subtraction on the screen legitimate: revenue,
 * commission and cost all describe the same set of orders.
 *
 * Commission is therefore joined THROUGH the order's referral, not read from
 * its own dates. A ledger row's paid_at is when the agent was transferred —
 * days or weeks after the sale — and mixing that into this window would
 * subtract one month's payouts from another month's revenue.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHAT IT REFUSES TO GUESS ──
 *
 * Three things are known to be unmeasurable, and each is COUNTED and returned
 * rather than absorbed:
 *
 *   · deals that closed with no order at all — real business, no money row
 *   · orders the gateway told us were refunded while the status still says
 *     paid (GatewayPaymentService deliberately does not flip it, so that a
 *     webhook cannot claw back an agent's commission) — they are still in the
 *     revenue figure, and the screen has to say so
 *   · orders whose product has no cost recorded — excluded from gross profit
 *     entirely, because a zero would report them as pure margin
 *
 * A dashboard that hides these looks more authoritative and is less true.
 */
class BusinessOverviewService
{
    /**
     * @return array<string, mixed>
     */
    public function build(?int $companyId, Carbon $from, Carbon $to): array
    {
        $revenue = $this->revenue($companyId, $from, $to);
        $commissionSatang = $this->commissionOnSalesIn($companyId, $from, $to);
        $cost = $this->cost($companyId, $from, $to);

        /*
         * Gross profit is computed over the orders we CAN cost, and the
         * revenue it is computed from is those same orders' revenue — not the
         * headline figure. Subtracting a full-period cost-of-nothing from a
         * full-period revenue would report the uncosted sales as pure profit,
         * which is the exact error the nullable column was designed to avoid.
         */
        $grossProfitSatang = $cost['costed_revenue_satang'] - $cost['cost_satang'] - $cost['costed_commission_satang'];

        return [
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                // Named in the response so the screen can print it. The axis
                // is the one thing a reader cannot infer from the numbers.
                'axis' => 'orders.paid_at',
            ],
            'money' => [
                'revenue_satang' => $revenue['revenue_satang'],
                'orders_paid' => $revenue['orders_paid'],
                'commission_satang' => $commissionSatang,
                'cost_satang' => $cost['cost_satang'],
                'gross_profit_satang' => $grossProfitSatang,
                /*
                 * Ratios computed HERE, not in the browser.
                 *
                 * "ค่าแนะนำต่อยอดขาย" is the number an owner of a commission
                 * business watches every month, and until now the system had
                 * both operands on one screen and divided them nowhere. Null
                 * rather than zero when there is no revenue: 0% commission on
                 * no sales is not a fact about the business.
                 */
                'commission_ratio' => $revenue['revenue_satang'] > 0
                    ? round($commissionSatang / $revenue['revenue_satang'] * 100, 2)
                    : null,
                'gross_margin_ratio' => $cost['costed_revenue_satang'] > 0
                    ? round($grossProfitSatang / $cost['costed_revenue_satang'] * 100, 2)
                    : null,
                'average_order_satang' => $revenue['orders_paid'] > 0
                    ? intdiv($revenue['revenue_satang'], $revenue['orders_paid'])
                    : null,
            ],
            'commission_pipeline' => $this->commissionPipeline($companyId),
            'monthly' => $this->monthly($companyId, $from, $to),
            'top_products' => $this->topProducts($companyId, $from, $to),
            'top_agents' => $this->topAgents($companyId, $from, $to),
            'clients' => $this->clients($companyId, $from, $to),
            'disclosures' => $this->disclosures($companyId, $from, $to, $cost),
        ];
    }

    /**
     * Money that actually arrived in the window.
     *
     * `status = paid` and `paid_at` inside the window, both. A paid order with
     * no paid_at exists (hand-edited rows), and counting it in the headline
     * while the monthly series below drops it is how a dashboard's total stops
     * matching the sum of its own chart — a divergence the existing agent
     * dashboard has today.
     *
     * @return array{revenue_satang: int, orders_paid: int}
     */
    private function revenue(?int $companyId, Carbon $from, Carbon $to): array
    {
        $row = $this->paidOrders($companyId, $from, $to)
            ->selectRaw('COALESCE(SUM(amount_satang), 0) AS revenue_satang, COUNT(*) AS orders_paid')
            ->first();

        return [
            'revenue_satang' => (int) ($row->revenue_satang ?? 0),
            'orders_paid' => (int) ($row->orders_paid ?? 0),
        ];
    }

    /**
     * The commission earned ON the sales in this window.
     *
     * Joined through referral_id, paid or not. "What did this quarter's
     * business cost us in commission" is a question about the sales, and a
     * ledger row's own dates answer a different one — when the agent was
     * transferred, which is a cash-flow question and is answered separately by
     * commissionPipeline() below.
     *
     * SUM over a signed column: a reversal row is negative
     * (2026_09_20_090000), so a refunded sale's commission nets itself out
     * here without any special case.
     */
    private function commissionOnSalesIn(?int $companyId, Carbon $from, Carbon $to): int
    {
        return (int) DB::table('commission_ledger')
            ->whereIn('referral_id', $this->paidOrders($companyId, $from, $to)->select('referral_id'))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->sum('amount_satang');
    }

    /**
     * Cost of goods, over the orders that HAVE a cost.
     *
     * Returns the matching revenue and commission alongside it, because gross
     * profit must be computed over the same subset — see build().
     *
     * @return array{cost_satang: int, costed_revenue_satang: int, costed_commission_satang: int, uncosted_orders: int}
     */
    private function cost(?int $companyId, Carbon $from, Carbon $to): array
    {
        $costed = $this->paidOrders($companyId, $from, $to)->whereNotNull('cost_satang_at_time');

        $row = (clone $costed)
            ->selectRaw('COALESCE(SUM(cost_satang_at_time), 0) AS cost_satang, COALESCE(SUM(amount_satang), 0) AS revenue_satang')
            ->first();

        $costedCommission = (int) DB::table('commission_ledger')
            ->whereIn('referral_id', (clone $costed)->select('referral_id'))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->sum('amount_satang');

        $uncosted = (int) $this->paidOrders($companyId, $from, $to)
            ->whereNull('cost_satang_at_time')
            ->count();

        return [
            'cost_satang' => (int) ($row->cost_satang ?? 0),
            'costed_revenue_satang' => (int) ($row->revenue_satang ?? 0),
            'costed_commission_satang' => $costedCommission,
            'uncosted_orders' => $uncosted,
        ];
    }

    /**
     * Where the money owed to agents currently stands — NOT windowed.
     *
     * Deliberately outside the date filter, because it is a balance and not a
     * flow: "what do we owe right now" does not have a September version. The
     * three buckets are the same money in three states, which is the thing the
     * owner could not see in one place before (it lived across the payout
     * screen, the queue and the dashboard).
     *
     * @return array<string, int>
     */
    private function commissionPipeline(?int $companyId): array
    {
        $unpaid = (int) DB::table('commission_ledger')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('payment_status', PaymentStatus::Pending->value)
            ->sum('amount_satang');

        $scheduled = (int) DB::table('commission_withdrawal_requests')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('status', [WithdrawalStatus::PendingReview->value, WithdrawalStatus::Approved->value])
            ->sum('amount_satang');

        $paid = (int) DB::table('commission_ledger')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('payment_status', PaymentStatus::Paid->value)
            ->sum('amount_satang');

        return [
            // Owed and not yet in a payout run. `scheduled` is a SUBSET of it
            // — a raised payout reserves ledger rows without settling them —
            // so the screen must not add these two together.
            'unpaid_satang' => $unpaid,
            'scheduled_satang' => $scheduled,
            'paid_satang' => $paid,
        ];
    }

    /**
     * Revenue by calendar month across the window.
     *
     * Built from the window the caller asked for rather than a hardcoded six
     * months — the thing every existing chart in this app gets wrong, and the
     * reason "ไตรมาสนี้เทียบไตรมาสก่อน" was impossible to answer.
     *
     * @return list<array{month: string, revenue_satang: int, orders: int}>
     */
    private function monthly(?int $companyId, Carbon $from, Carbon $to): array
    {
        /*
         * Bucketed in PHP, not with a SQL date function.
         *
         * strftime() is SQLite's and DATE_FORMAT() is MySQL's, and this app
         * runs on both — tests on one, production on the other. A report that
         * works in CI and throws in production is the worst possible place to
         * discover the difference, so the two columns come back raw and Carbon
         * does the grouping. AgentDashboardMetricsService sets the precedent.
         */
        $rows = collect();

        foreach ($this->paidOrders($companyId, $from, $to)->select('paid_at', 'amount_satang')->get() as $order) {
            $key = Carbon::parse($order->paid_at)->format('Y-m');
            $bucket = $rows->get($key, ['revenue_satang' => 0, 'orders' => 0]);
            $rows[$key] = [
                'revenue_satang' => $bucket['revenue_satang'] + (int) $order->amount_satang,
                'orders' => $bucket['orders'] + 1,
            ];
        }

        $out = [];
        // Every month in the window, including the empty ones. A chart that
        // silently omits a month with no sales draws a line straight from
        // August to October and reads as continuity.
        for ($cursor = $from->copy()->startOfMonth(); $cursor->lessThanOrEqualTo($to); $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $row = $rows->get($key, ['revenue_satang' => 0, 'orders' => 0]);

            $out[] = [
                'month' => $key,
                'revenue_satang' => (int) $row['revenue_satang'],
                'orders' => (int) $row['orders'],
            ];
        }

        return $out;
    }

    /**
     * Best sellers, FROM ORDERS.
     *
     * The existing "มุมมองสินค้า" screen counts referrals that reached
     * Complete Payment and multiplies by the product's CURRENT price — so a
     * price rise rewrites last year's revenue, and a closed deal with no order
     * counts as a sale with money behind it. Neither is true here: this is the
     * money that arrived, grouped by the product it arrived for.
     *
     * @return list<array<string, mixed>>
     */
    private function topProducts(?int $companyId, Carbon $from, Carbon $to): array
    {
        return $this->paidOrders($companyId, $from, $to)
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->selectRaw(
                'orders.product_id, products.name AS product_name, '.
                'COUNT(*) AS units, '.
                'COALESCE(SUM(orders.amount_satang), 0) AS revenue_satang, '.
                'COALESCE(SUM(orders.cost_satang_at_time), 0) AS cost_satang, '.
                // How many of this product's sales we could not cost. The
                // screen needs it per row: "48% margin" over three of eleven
                // sales is a different claim from "48% margin".
                'SUM(CASE WHEN orders.cost_satang_at_time IS NULL THEN 1 ELSE 0 END) AS uncosted_units'
            )
            ->groupBy('orders.product_id', 'products.name')
            ->orderByDesc('revenue_satang')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'units' => (int) $row->units,
                'revenue_satang' => (int) $row->revenue_satang,
                'uncosted_units' => (int) $row->uncosted_units,
                // NULL, not a number, when nothing about this product could be
                // costed — the row says "ตั้งต้นทุนก่อน" rather than "0%".
                'cost_satang' => (int) $row->uncosted_units === (int) $row->units ? null : (int) $row->cost_satang,
            ])
            ->all();
    }

    /**
     * Agents ranked by the money they brought IN.
     *
     * The existing dashboard's "top agents" ranks by commission already PAID,
     * which answers a different question (who have we disbursed the most to)
     * and moves when payouts are run rather than when sales happen.
     *
     * @return list<array<string, mixed>>
     */
    private function topAgents(?int $companyId, Carbon $from, Carbon $to): array
    {
        return $this->paidOrders($companyId, $from, $to)
            ->join('users', 'users.id', '=', 'orders.agent_id')
            ->selectRaw('orders.agent_id, users.name AS agent_name, COUNT(*) AS orders, COALESCE(SUM(orders.amount_satang), 0) AS revenue_satang')
            ->groupBy('orders.agent_id', 'users.name')
            ->orderByDesc('revenue_satang')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'agent_id' => (int) $row->agent_id,
                'agent_name' => $row->agent_name,
                'orders' => (int) $row->orders,
                'revenue_satang' => (int) $row->revenue_satang,
            ])
            ->all();
    }

    /**
     * New clients in the window, and deals closed.
     *
     * Clients have never been bucketed by date anywhere in this app — the
     * dashboard counts them all-time and buckets AGENTS by month, which reads
     * as growth and is not.
     *
     * @return array{new_clients: int, deals_closed: int}
     */
    private function clients(?int $companyId, Carbon $from, Carbon $to): array
    {
        $newClients = (int) DB::table('clients')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();

        $dealsClosed = (int) $this->paidOrders($companyId, $from, $to)
            ->distinct()
            ->count('referral_id');

        return ['new_clients' => $newClients, 'deals_closed' => $dealsClosed];
    }

    /**
     * What the figures above could not measure.
     *
     * @param  array{uncosted_orders: int}  $cost
     * @return array<string, int>
     */
    private function disclosures(?int $companyId, Carbon $from, Carbon $to, array $cost): array
    {
        /*
         * Orders the GATEWAY reported refunded while the status still says
         * paid. GatewayPaymentService stamps refund_reported_at and stops
         * there, on purpose — a webhook must not claw back an agent's
         * commission by itself. The money is gone and the revenue figure above
         * still counts it, so the count is surfaced rather than quietly
         * subtracted: only a human can decide which it is.
         */
        $reportedRefunds = (int) $this->paidOrders($companyId, $from, $to)
            ->whereNotNull('refund_reported_at')
            ->count();

        /*
         * Deals that reached the end of the pipeline with no paid order behind
         * them. Real business with no money row to count — the same
         * disclosure the agent dashboard already makes, carried here because
         * it is the reason revenue can be lower than the deal count implies.
         */
        $closedWithoutOrder = (int) DB::table('referrals')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('current_stage', 'complete_payment')
            ->whereBetween('referrals.updated_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('orders')
                ->whereColumn('orders.referral_id', 'referrals.id')
                ->where('orders.status', OrderStatus::Paid->value))
            ->count();

        return [
            'orders_with_reported_refund' => $reportedRefunds,
            'closed_deals_without_paid_order' => $closedWithoutOrder,
            'orders_without_cost' => $cost['uncosted_orders'],
        ];
    }

    /**
     * THE ONE QUERY EVERY FIGURE ABOVE NARROWS: paid orders in the window.
     *
     * A single definition of "a sale in this period", so no two numbers on the
     * screen can be counting different sets. Tenant scoping is explicit
     * because these are query-builder calls and TenantScope does not reach
     * them (BR-6) — a null companyId is the deliberate Super-Admin read-across,
     * never an accident.
     */
    private function paidOrders(?int $companyId, Carbon $from, Carbon $to): \Illuminate\Database\Query\Builder
    {
        return DB::table('orders')
            ->when($companyId !== null, fn ($q) => $q->where('orders.company_id', $companyId))
            ->where('orders.status', OrderStatus::Paid->value)
            ->whereNotNull('orders.paid_at')
            ->whereBetween('orders.paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }
}
