<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Services\Referral\ClosedDealPredicate;
use Illuminate\Support\Collection;

/**
 * Product-view IA item 2.2 ("สินค้าขายดี แบ่งเกรด A-D") — human-confirmed
 * 2026-07-22: computed live from real sales (never a manually-set field),
 * Pareto-style (A = top products making up the first 80% of estimated
 * revenue, B = next up to 95%, C = the rest with at least one sale, D =
 * zero sales in the window).
 *
 * "Sold" = a Referral that has REACHED Complete Payment, per the one
 * shared App\Services\Referral\ClosedDealPredicate (TASK-179 §3.1, human
 * decision D4). TASK-180 §3 (B1, 2026-08-13): this docblock used to claim
 * "reached or passed CompletePayment" and the code underneath enumerated
 * exactly two stages, [complete_payment, ongoing_next_meeting]. Once
 * ADR-026 let a template continue into จัดส่ง / นัดใช้บริการ / ติดตามผล, a
 * product whose deals get advanced into a post-sale stage LOST sold_count
 * and could fall to grade D while it was in fact selling. ABC grading
 * drives merchandising decisions, so grades can legitimately MOVE as a
 * result of this correction — see the task report.
 *
 * There is no historical sale-price snapshot anywhere in this schema
 * (CommissionLedger.amount_satang is the COMMISSION paid, not the sale
 * price — see that model's docblock), so revenue here is an ESTIMATE:
 * sold_count x the product's CURRENT price_satang. This is disclosed to
 * the human as an estimate in the Resource/UI, never presented as an
 * exact historical figure — same "never fabricate, always disclose the
 * approximation" discipline as the Agent Overview dashboard's at-risk
 * calculation.
 *
 * Window is caller-supplied (not hardcoded) and filters on
 * Referral.submitted_at — same "submitted_at as the closest available
 * proxy for a real event date" choice already made in
 * AgentManagementView's Active/At-risk segmentation, for consistency.
 */
class ProductGradingService
{
    /**
     * @return Collection<int, array{product_id: int, product_name: string, sold_count: int, estimated_revenue_satang: int, revenue_share_percent: float, cumulative_percent: float, grade: string}>
     */
    public function computeGrades(User $actor, ?int $windowDays): Collection
    {
        $products = Product::query()
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('company_id', $actor->company_id))
            ->orderBy('name')
            ->get(['id', 'name', 'price_satang', 'company_id']);

        $cutoff = $windowDays !== null ? now()->subDays($windowDays) : null;

        // BR-6: the same company_id narrowing the $products query above
        // uses, applied BEFORE the predicate — ClosedDealPredicate only
        // narrows to "closed", it is never a tenant filter (see its
        // docblock). A Super Admin with no company selected intentionally
        // spans companies here, exactly as $products does; product_id keys
        // stay unambiguous because a product belongs to one company.
        $soldCountsQuery = Referral::query()
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('referrals.company_id', $actor->company_id))
            ->when($cutoff, fn ($q) => $q->where('referrals.submitted_at', '>=', $cutoff));

        // TASK-180 §3 (B1) — the ONE closed-deal answer, replacing this
        // Service's own SOLD_STAGES list. A deal parked at จัดส่ง /
        // นัดใช้บริการ / ติดตามผล is a deal the customer has PAID for; it
        // must keep counting towards the product that sold it.
        ClosedDealPredicate::apply($soldCountsQuery);

        $soldCounts = $soldCountsQuery
            ->selectRaw('product_id, COUNT(*) as sold_count')
            ->groupBy('product_id')
            ->pluck('sold_count', 'product_id');

        $rows = $products->map(function (Product $product) use ($soldCounts) {
            $soldCount = (int) ($soldCounts[$product->id] ?? 0);

            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sold_count' => $soldCount,
                'estimated_revenue_satang' => $soldCount * $product->price_satang,
            ];
        })->sortByDesc('estimated_revenue_satang')->values();

        $totalRevenue = $rows->sum('estimated_revenue_satang');
        $cumulative = 0;

        return $rows->map(function (array $row) use (&$cumulative, $totalRevenue) {
            $share = $totalRevenue > 0 ? ($row['estimated_revenue_satang'] / $totalRevenue) * 100 : 0.0;
            $cumulative += $share;

            $row['revenue_share_percent'] = round($share, 2);
            $row['cumulative_percent'] = round(min($cumulative, 100), 2);
            $row['grade'] = match (true) {
                $row['sold_count'] === 0 => 'D',
                $cumulative <= 80.0 => 'A',
                $cumulative <= 95.0 => 'B',
                default => 'C',
            };

            return $row;
        });
    }
}
