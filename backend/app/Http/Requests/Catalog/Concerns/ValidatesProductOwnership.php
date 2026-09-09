<?php

namespace App\Http\Requests\Catalog\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * 2026-09-09 — "a product this company may point at", now that a product can
 * belong to nobody.
 *
 * ── WHAT WAS WRONG ──
 *
 * Thirteen Form Requests each wrote the same rule by hand:
 *
 *     Rule::exists('products', 'id')->where('company_id', $companyId)
 *
 * — correct for as long as every product belonged to exactly one company.
 * ADR-040 ended that: a product with `company_id NULL` is owned by the
 * PLATFORM and sold by many companies. Every one of those thirteen rules
 * refuses such a product, so the day a company's product is promoted to the
 * platform, its affiliate links, referrals, share links, storefront banners,
 * recommendation pins, commission rules and academy modules all start
 * answering "the selected product id is invalid" about a product sitting
 * right there on the screen.
 *
 * Exactly the shape of the brand/category failure reported one day earlier
 * (ValidatesProductTaxonomy) — the list offers what the save refuses.
 *
 * ── TWO RULES, NOT ONE, AND THE LINE BETWEEN THEM ──
 *
 * ADR-040's load-bearing sentence is that PERMISSION TO SELL NEVER INHERITS:
 * a platform product is visible to everybody and sellable only by the
 * companies explicitly switched on in `company_product_settings`. So
 * "widen it to any platform product" is the wrong fix everywhere that
 * widening would let a company put a product in front of a customer.
 *
 *   sellableProductRule() — for anything a CUSTOMER ends up seeing: an
 *   affiliate link, a referral, a share link, a storefront banner, a
 *   recommendation pin. Own product, or a platform product this company has
 *   actually been granted. Anything looser mints a public page for a product
 *   the company was never allowed to sell.
 *
 *   configurableProductRule() — for a company's own INTERNAL configuration
 *   about a product: commission rules, override rules, academy modules. Own
 *   product, or any platform product. Looser on purpose: requiring the grant
 *   first creates an ordering trap (you cannot prepare a company's rates
 *   before switching the product on for them), and a rule attached to a
 *   product the company cannot sell simply never fires — it is clutter, not
 *   a leak. The row is still stamped with their own company_id, so nothing
 *   crosses a tenant boundary either way (BR-6).
 *
 * Another company's product stays refused by BOTH. That was the reason the
 * hand-written rule existed and none of this weakens it.
 */
trait ValidatesProductOwnership
{
    /**
     * A product this company may actually put in front of a customer: its
     * own, or a platform product switched on for it in
     * `company_product_settings` (ADR-040 — permission never inherits).
     *
     * A NULL $companyId means the caller has no company scope to answer for;
     * the rule then collapses to the company's own products only, which for a
     * null scope is nothing — deliberately, since "no scope" must never mean
     * "everything".
     */
    protected function sellableProductRule(?int $companyId): Exists
    {
        return Rule::exists('products', 'id')->where(
            fn ($query) => $query
                ->whereNull('deleted_at')
                ->where(fn ($scoped) => $scoped
                    ->where('company_id', $companyId)
                    ->orWhere(fn ($shared) => $shared
                        ->whereNull('company_id')
                        ->whereExists(fn ($granted) => $granted
                            ->selectRaw('1')
                            ->from('company_product_settings')
                            ->whereColumn('company_product_settings.product_id', 'products.id')
                            ->where('company_product_settings.company_id', $companyId)
                            ->where('company_product_settings.is_active', true)))),
        );
    }

    /**
     * A product this company may configure for itself: its own, or any
     * platform product. See the trait docblock for why this one does not
     * require the sell grant.
     */
    protected function configurableProductRule(?int $companyId): Exists
    {
        return Rule::exists('products', 'id')->where(
            fn ($query) => $query
                ->whereNull('deleted_at')
                ->where(fn ($scoped) => $scoped
                    ->where('company_id', $companyId)
                    ->orWhereNull('company_id')),
        );
    }
}
