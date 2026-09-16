<?php

namespace App\Services\Sales;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Give past sales a cost, once, so gross profit has a history to report.
 *
 * ── WHY THIS EXISTS AT ALL ──
 *
 * `orders.cost_satang_at_time` is stamped when the order is created. That is
 * the right rule — it stops a supplier price rise from silently rewriting last
 * quarter's margin — and it means a cost typed in today reaches no sale that
 * has already happened. For a company whose entire order history predates the
 * column, the gross-profit figure is therefore empty and stays empty.
 *
 * This is the one-time bridge out of that: for orders with no cost recorded,
 * copy the product's CURRENT cost and mark the row as having been estimated.
 *
 * ── WHAT IT DELIBERATELY WILL NOT DO ──
 *
 * It never touches an order that already has a cost — not a real snapshot, and
 * not one it estimated on an earlier run. So running it twice is safe, and a
 * later correction to a product's cost cannot reach back and move a margin
 * that has already been read and acted on. Fixing a genuinely wrong historical
 * cost is a deliberate act on that order, not a side effect of editing a
 * catalogue row.
 *
 * It also never invents a cost: a product with none is left alone, and the
 * overview keeps counting those orders as unmeasurable.
 */
class OrderCostBackfillService
{
    /**
     * How many past sales COULD be given a cost right now.
     *
     * Asked by the overview so the button only appears when it would do
     * something, and so the confirmation can name the number before anybody
     * presses it.
     */
    public function pendingCount(?int $companyId): int
    {
        return (int) $this->backfillable($companyId)->count();
    }

    /**
     * Do it.
     *
     * @return int how many orders were given a cost
     */
    public function run(?int $companyId, User $actor): int
    {
        $orders = $this->backfillable($companyId)
            ->select('orders.id', 'orders.company_id', 'products.cost_satang')
            ->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($orders, $actor) {
            foreach ($orders as $order) {
                DB::table('orders')->where('id', $order->id)->update([
                    'cost_satang_at_time' => (int) $order->cost_satang,
                    'cost_backfilled_at' => now(),
                ]);
            }

            /*
             * ONE audit row for the batch, not one per order.
             *
             * What a reader needs later is "somebody applied current costs to
             * history on this date, to this many orders" — the decision. A row
             * per order would bury that decision in noise on the one screen
             * where it has to stay findable, and the orders themselves already
             * carry cost_backfilled_at.
             */
            AuditLog::create([
                'company_id' => $orders->first()->company_id,
                'actor_user_id' => $actor->id,
                'action' => 'orders.cost_backfilled',
                'auditable_type' => \App\Models\Order::class,
                'auditable_id' => $orders->first()->id,
                'old_values' => null,
                'new_values' => [
                    'orders_updated' => $orders->count(),
                    'order_ids' => $orders->pluck('id')->all(),
                ],
                'ip_address' => request()?->ip(),
            ]);
        });

        return $orders->count();
    }

    /**
     * Paid or not — every order with no cost, whose product has one.
     *
     * Not narrowed to paid orders even though only paid ones reach the
     * overview: an order that is paid next week would otherwise be left
     * without a cost forever, since OrderService only stamps at creation.
     */
    private function backfillable(?int $companyId): \Illuminate\Database\Query\Builder
    {
        return DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->when($companyId !== null, fn ($q) => $q->where('orders.company_id', $companyId))
            ->whereNull('orders.cost_satang_at_time')
            ->whereNotNull('products.cost_satang');
    }
}
