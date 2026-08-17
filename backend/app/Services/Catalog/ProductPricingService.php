<?php

namespace App\Services\Catalog;

use App\Enums\PromotionStatus;
use App\Models\Product;
use App\Models\ProductPricePromotion;
use App\Models\Scopes\TenantScope;

/**
 * TASK-136 (risk R1) — THE single answer to "what does this product
 * actually cost right now".
 *
 * WHY THIS EXISTS
 * ---------------
 * Two different parts of the system used to answer that question two
 * different ways:
 *
 *   - CommissionService (TASK-047) resolved the active
 *     ProductPricePromotion and computed BR-4 commission from the
 *     DISCOUNTED price.
 *   - OrderService::createForReferral() snapshotted
 *     `product.price_satang` — the LIST price — onto the order the
 *     customer is asked to pay.
 *
 * While only an agent ever saw an order, that inconsistency was an
 * internal oddity. TASK-136 puts a *customer* in front of it: the public
 * share page advertises a promotional price and the checkout would have
 * charged the list price. That is a consumer complaint, not a bug report
 * (TASK-132 §Risks R1), so the two paths are collapsed into this one
 * method and every caller reads the same number.
 *
 * The promotion lookup itself is TASK-047's, moved here verbatim — its
 * reasoning is preserved below because it is still the reasoning.
 *
 * BR-3: satang stays an integer end to end. Nothing here divides by 100.
 */
class ProductPricingService
{
    /**
     * The price a customer is charged for this product RIGHT NOW —
     * the active promotion's discounted price if one is running, else
     * the product's list price.
     *
     * "Right now" is deliberate and matches TASK-047's rule for
     * commission: the promotion that counts is the one active at the
     * moment the amount is fixed, never one that was active earlier
     * (at referral submission) or that starts later.
     */
    public function effectivePriceSatang(Product $product): int
    {
        return $this->activePromotion($product)?->discounted_price_satang
            ?? $product->price_satang;
    }

    /**
     * TASK-047 — human-confirmed (previously flagged as "// TODO:
     * CONFIRM" in the product_price_promotions migration): resolves
     * whichever ProductPricePromotion is active for $product RIGHT NOW,
     * if any.
     *
     * Explicit `where('company_id', ...)` rather than relying on
     * ProductPricePromotion's own TenantScope: TenantScope exempts Super
     * Admin entirely (§5) AND no-ops completely on an unauthenticated
     * public route — which, since TASK-136, is exactly where this runs
     * (the anonymous checkout). A scope-only filter would therefore be
     * no filter at all in the context that matters most. BR-6.
     *
     * TASK-136 additionally strips TenantScope outright rather than
     * leaving it stacked on top of the explicit company filter: leaving
     * it on would mean a Company Admin browsing another company's
     * product (Super Admin case) silently resolved NO promotion instead
     * of the right one — i.e. the same number would depend on WHO asked,
     * which is the property this class exists to remove.
     *
     * `status = Active` is filtered in the query; the date-window half of
     * "currently active" (starts_at/ends_at) is delegated to
     * isCurrentlyActive() rather than duplicated here as a second WHERE,
     * reusing that model method as the single source of truth for the
     * date logic. No unique constraint stops two overlapping Active
     * promotions existing for the same product (a data-integrity gap the
     * product_price_promotions migration doesn't close) — if that ever
     * happens, `latest('id')` is a deterministic (not silently random)
     * tie-break, picking the most-recently-created row.
     */
    public function activePromotion(Product $product): ?ProductPricePromotion
    {
        return ProductPricePromotion::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('status', PromotionStatus::Active)
            ->latest('id')
            ->get()
            ->first(fn (ProductPricePromotion $promotion) => $promotion->isCurrentlyActive());
    }
}
