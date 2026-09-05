<?php

namespace App\Services\Catalog;

use App\Models\ProductCatalogItem;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private ProductCatalogPropagationService $propagation) {}

    /**
     * TASK-251 — creating the shared item now also creates every company's
     * (disabled) listing of it.
     *
     * ── WHY IT HAPPENS HERE AND NOT IN THE CONTROLLER ──
     *
     * "A product in the shared catalog is in every company" is the RULE the
     * human asked for, not a convenience of one screen. Putting it in the
     * controller would make it true of the admin form and false of every
     * other caller — a seeder, a console command, a future import — and the
     * difference would show up as a company mysteriously missing one product.
     *
     * ONE TRANSACTION with the item itself: a catalog item that exists in
     * nowhere is worse than one that failed to save, because it looks like
     * success and its absence is only discovered company by company.
     *
     * The returned item is unchanged — propagation adds rows to `products`,
     * never to this row. How many companies were reached is in the audit
     * trail (`catalog_item.propagated`) and can be read back from
     * `linked_product_count`.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ProductCatalogItem
    {
        return DB::transaction(function () use ($data) {
            $item = ProductCatalogItem::create($data);

            $this->propagation->propagateItemToAllCompanies($item);

            return $item;
        });
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
