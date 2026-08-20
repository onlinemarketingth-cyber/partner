<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-036 — the Super-Admin catalog management shape (TASK-213/214).
// Distinct from how a LINKED product exposes its identity via
// ProductResource's effective_ fields — this is the "edit the shared
// item itself" shape, listing every company currently linked to it
// (`linked_product_count`) so Super Admin can see the blast radius
// before editing shared content or deleting the item.
class ProductCatalogItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'catalog_brand_id' => $this->catalog_brand_id,
            'catalog_category_id' => $this->catalog_category_id,
            'catalog_brand' => new CatalogBrandResource($this->whenLoaded('catalogBrand')),
            'catalog_category' => new CatalogCategoryResource($this->whenLoaded('catalogCategory')),
            'name' => $this->name,
            'description' => $this->description,
            'spec_description' => $this->spec_description,
            'is_active' => $this->is_active,
            'media' => ProductCatalogMediaResource::collection($this->whenLoaded('media')),
            'specs' => ProductCatalogSpecResource::collection($this->whenLoaded('specs')),
            // TASK-213 — how many companies currently link a product to
            // this item; Super Admin needs this before editing shared
            // content (every linked company sees the change at once) or
            // deleting it (blocked by restrictOnDelete — see the
            // products.catalog_item_id migration).
            'linked_product_count' => $this->whenCounted('products'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
