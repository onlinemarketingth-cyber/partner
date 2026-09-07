<?php

namespace App\Services\Catalog;

use App\Enums\PromotionStatus;
use App\Models\CompanyProductSetting;
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
     * product id + company id -> that company's own price, or null.
     *
     * @var array<string, int|null>
     */
    private array $ownPriceMemo = [];

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
    public function effectivePriceSatang(Product $product, ?int $companyId = null): int
    {
        $companyId ??= $product->company_id;

        return $this->activePromotion($product, $companyId)?->discounted_price_satang
            ?? $this->listPriceSatang($product, $companyId);
    }

    /**
     * TASK-254 / ADR-040 — the price BEFORE any promotion, for the company
     * that is asking.
     *
     * A company that has set its own price uses it; one that has not INHERITS
     * the product's central price. That fallback is the human's decision
     * ("ถ้าไม่มีการแก้ไขให้ใช้ราคากลางไปก่อน") and it is safe for BR-7 because
     * the inherited number is one a Super Admin typed on the product — not one
     * this system invented.
     *
     * A company-owned product ignores all of it: nothing else can own a price
     * for a row that belongs to one company already.
     */
    public function listPriceSatang(Product $product, ?int $companyId = null): int
    {
        $companyId ??= $product->company_id;

        // `?? central` and not `?: central`: 0 satang is a price a Super Admin
        // may deliberately set (a free onboarding item), and treating it as
        // "unset" would silently charge the central price instead.
        return (int) ($this->ownPriceSatang($product, $companyId) ?? $product->price_satang);
    }

    /**
     * TASK-256 — the company's OWN price, or null when it has not set one.
     *
     * listPriceSatang() answers "what does this company pay", which is never
     * null and therefore cannot distinguish a company that deliberately priced
     * the product at the central figure from one that has simply never
     * touched it. The admin screen has to tell those apart: one shows a price,
     * the other shows "(ราคากลาง)" and will move on its own the next time a
     * Super Admin edits the central number.
     *
     * Null for a company-owned product too — there is nothing else that could
     * own a price for a row that already belongs to one company.
     */
    public function ownPriceSatang(Product $product, ?int $companyId = null): ?int
    {
        $companyId ??= $product->company_id;

        if (! $product->isShared() || $companyId === null) {
            return null;
        }

        $key = $product->id.':'.$companyId;

        /*
         * Memoised because ProductResource asks twice per row — once for the
         * effective price, once to say whether it is inherited — and a
         * paginated catalogue would otherwise pay for both. This is a READ
         * cache on one instance: resources take a request-scoped instance
         * (RequestScopedService), writers construct their own, so a save
         * never reads back a stale number here.
         */
        if (array_key_exists($key, $this->ownPriceMemo)) {
            return $this->ownPriceMemo[$key];
        }

        $ownPrice = CompanyProductSetting::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->value('price_satang');

        return $this->ownPriceMemo[$key] = $ownPrice === null ? null : (int) $ownPrice;
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
    public function activePromotion(Product $product, ?int $companyId = null): ?ProductPricePromotion
    {
        /*
         * TASK-254 / ADR-040 — a promotion belongs to a COMPANY, and a shared
         * product has none of its own, so the caller says who is asking. Left
         * to `$product->company_id` a shared product would look for a
         * promotion with a NULL company — matching nothing, or worse, matching
         * a row that has no owner.
         */
        $companyId ??= $product->company_id;

        if ($companyId === null) {
            // Nobody in particular is asking (a Super Admin reading across
            // companies). No company means no promotion, not "any promotion".
            return null;
        }

        return ProductPricePromotion::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->where('status', PromotionStatus::Active)
            ->latest('id')
            ->get()
            ->first(fn (ProductPricePromotion $promotion) => $promotion->isCurrentlyActive());
    }
}
