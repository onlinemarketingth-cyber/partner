<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\LinkProductCatalogRequest;
use App\Http\Requests\Catalog\UnlinkProductCatalogRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductCatalogItem;
use App\Services\Catalog\ProductCatalogLinkService;

// ADR-036 §3/§6 — the ONLY controller that ever writes
// products.catalog_item_id. Deliberately its own controller rather than
// two more ProductController actions: linking is a distinct,
// Super-Admin-only governance action (not a general product edit), and
// keeping it separate means ProductController's authorizeResource() (Company
// Admin can update their own product) never accidentally covers it.
class ProductCatalogLinkController extends Controller
{
    public function store(LinkProductCatalogRequest $request, Product $product, ProductCatalogLinkService $service): ProductResource
    {
        $catalogItem = ProductCatalogItem::findOrFail($request->validated('catalog_item_id'));

        $linked = $service->link($product, $catalogItem);

        return new ProductResource($linked->load(['catalogItem.catalogBrand', 'catalogItem.catalogCategory', 'company']));
    }

    public function destroy(UnlinkProductCatalogRequest $request, Product $product, ProductCatalogLinkService $service): ProductResource
    {
        $unlinked = $service->unlink($product, $request->validated());

        return new ProductResource($unlinked->load(['brand', 'category', 'company']));
    }
}
