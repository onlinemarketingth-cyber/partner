<?php

namespace App\Services\Catalog;

use App\Models\ProductCatalogItem;

// ADR-036 §2 — create/update the shared catalog item ITSELF (name,
// brand, category, description, spec_description). Deliberately separate
// from ProductCatalogLinkService, which only ever touches the link
// (products.catalog_item_id) and never this item's own content — keeping
// "edit the shared thing" and "point a product at the shared thing" as
// two distinct actions with two distinct authorization checks (both
// still Super-Admin-only via ProductCatalogItemPolicy, but conceptually
// different enough in TASK-213's API that they deserve separate Service
// methods rather than one that tries to do both).
class ProductCatalogItemService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ProductCatalogItem
    {
        return ProductCatalogItem::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductCatalogItem $catalogItem, array $data): ProductCatalogItem
    {
        $catalogItem->update($data);

        return $catalogItem;
    }
}
