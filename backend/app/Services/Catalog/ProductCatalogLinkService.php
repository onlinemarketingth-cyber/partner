<?php

namespace App\Services\Catalog;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCatalogItem;
use App\Models\ProductCategory;
use App\Models\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;

// ADR-036 §3/§6 (TASK-212/213) — the ONLY place products.catalog_item_id
// is ever written. Super-Admin-only at the Policy layer (ProductPolicy::
// update() + ProductCatalogItemPolicy::view(), checked by the Form
// Request/Controller in TASK-213 before this Service is ever called —
// this class itself does not re-check authorization, matching every
// other *Service in this namespace).
//
// link() and unlink() both touch ONLY the five identity columns
// (catalog_item_id + name/brand_id/category_id/description/
// spec_description) — price_satang, commission_plan_type,
// commission_rules, pipeline_template_id, voucher/shipping config, etc.
// are completely untouched by either direction, because those already
// live independently per company (ADR-036 §3's whole point: "แค่ราคา
// กับค่าคอม ที่แต่ละบริษัทตั้งเองอิสระ").
class ProductCatalogLinkService
{
    /**
     * Point $product at the shared identity in $catalogItem.
     *
     * name/description/spec_description are cleared: they are pure display
     * text, Product::effectiveName() and its siblings already prefer the
     * catalog item, and clearing them means a raw DB read (a report query,
     * an export, an engineer who forgets the resolver exists) can never show
     * stale local text beside the catalog's current value.
     *
     * brand_id/category_id are NOT cleared — reversed by the human on
     * 2026-08-19 (TASK-206), after the audit below. They used to be nulled
     * for the same tidiness reason, but unlike name/description they are not
     * display-only: three separate systems read `products.category_id` (and
     * the product filters read `brand_id`) directly, so nulling them broke
     * real behaviour rather than merely hiding a duplicate string:
     *
     *   1. CommissionService::resolveCommissionRule() — a category-scoped
     *      commission rule (`product_id IS NULL AND product_category_id = X`,
     *      TASK-028) can no longer match, so the sale silently falls through
     *      to the company-default rate. That is a WRONG PAYOUT (BR-2), and an
     *      immutable ledger row records it (BR-4) — the worst class of bug in
     *      this codebase.
     *   2. PipelineTemplateResolver::categoryTemplateId() — the middle rung
     *      of ADR-026's `product ?? category ?? company` chain disappears, so
     *      the referral gets the company-default journey instead of the
     *      category's.
     *   3. ProductController::index's `brand_id`/`category_id` filters, the
     *      Admin product list and the Agent Portal's ProductBrowseView facets
     *      — a linked product becomes unfindable by brand or category.
     *
     * So the product keeps pointing at ITS OWN company's brand/category row
     * (BR-6 — those tables stay per-company), resolved by name from the
     * catalog item's global brand/category, created on the spot if that
     * company does not have one yet. Display still comes from the catalog
     * (ProductResource), so a Super Admin renaming the shared brand changes
     * every company's label; the local row is the join target that keeps
     * commission, pipeline and filtering working.
     */
    public function link(Product $product, ProductCatalogItem $catalogItem): Product
    {
        DB::transaction(function () use ($product, $catalogItem) {
            $catalogItem->loadMissing(['catalogBrand', 'catalogCategory']);

            $product->update([
                'catalog_item_id' => $catalogItem->id,
                'name' => null,
                'brand_id' => $this->localBrandId($product, $catalogItem),
                'category_id' => $this->localCategoryId($product, $catalogItem),
                'description' => null,
                'spec_description' => null,
            ]);
        });

        return $product->fresh();
    }

    /**
     * TASK-206 — repair a product that was linked BEFORE this fix existed
     * (its brand_id/category_id were nulled by the old link()).
     *
     * Idempotent and additive: it only ever fills a null, never overwrites a
     * brand/category an admin has since set by hand, and returns false when
     * there was nothing to do — which is what lets
     * `catalog:backfill-linked-taxonomy` be re-run safely.
     */
    public function backfillLocalTaxonomy(Product $product): bool
    {
        if ($product->catalog_item_id === null) {
            return false;
        }

        if ($product->brand_id !== null && $product->category_id !== null) {
            return false;
        }

        // withTrashed: a soft-deleted catalog item still leaves its linked
        // products stranded, and those are exactly the rows worth repairing.
        $catalogItem = ProductCatalogItem::withTrashed()
            ->with(['catalogBrand', 'catalogCategory'])
            ->find($product->catalog_item_id);

        if ($catalogItem === null) {
            return false;
        }

        DB::transaction(function () use ($product, $catalogItem) {
            $product->update([
                'brand_id' => $product->brand_id ?? $this->localBrandId($product, $catalogItem),
                'category_id' => $product->category_id ?? $this->localCategoryId($product, $catalogItem),
            ]);
        });

        return true;
    }

    /**
     * This company's own `brands` row whose name matches the catalog brand,
     * created if absent.
     *
     * TenantScope is bypassed deliberately: the actor here is a Super Admin
     * (ADR-036 §5), for whom the scope is a no-op anyway, and the row must be
     * looked up in the PRODUCT's company — never the actor's.
     */
    private function localBrandId(Product $product, ProductCatalogItem $catalogItem): ?int
    {
        $name = $catalogItem->catalogBrand?->name;

        if ($name === null) {
            // A catalog item always has a brand (FK is NOT NULL), so this is
            // unreachable in practice — but returning null is still safer
            // than inventing a brand named "".
            return $product->brand_id;
        }

        return Brand::withoutGlobalScope(TenantScope::class)
            ->firstOrCreate(
                ['company_id' => $product->company_id, 'name' => $name],
                ['is_active' => true],
            )->id;
    }

    private function localCategoryId(Product $product, ProductCatalogItem $catalogItem): ?int
    {
        $name = $catalogItem->catalogCategory?->name;

        if ($name === null) {
            return $product->category_id;
        }

        return ProductCategory::withoutGlobalScope(TenantScope::class)
            ->firstOrCreate(
                ['company_id' => $product->company_id, 'name' => $name],
                // sort_order/icon are presentation-only and belong to the
                // company; a mirrored category starts at the end of the list
                // with no icon rather than guessing the catalog's.
                ['is_active' => true, 'sort_order' => 0],
            )->id;
    }

    /**
     * Detach $product from its catalog item, restoring it to a fully
     * standalone product. The caller (TASK-213 Form Request) MUST supply
     * a complete local identity — name/brand_id/category_id are required
     * again the instant catalog_item_id goes back to null, the exact
     * same NOT NULL contract every other standalone product already has
     * (the DB migration only relaxed the COLUMN, not this business rule
     * — see 2026_08_18_120600_make_brand_category_name_nullable_on_products_table's
     * own docblock: "the business rule itself is enforced in the Form
     * Request/Service").
     *
     * @param  array{name: string, brand_id: int, category_id: int, description?: ?string, spec_description?: ?string}  $localIdentity
     */
    public function unlink(Product $product, array $localIdentity): Product
    {
        DB::transaction(function () use ($product, $localIdentity) {
            $product->update([
                'catalog_item_id' => null,
                'name' => $localIdentity['name'],
                'brand_id' => $localIdentity['brand_id'],
                'category_id' => $localIdentity['category_id'],
                'description' => $localIdentity['description'] ?? null,
                'spec_description' => $localIdentity['spec_description'] ?? null,
            ]);
        });

        return $product->fresh();
    }
}
