<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductCatalogItemRequest;
use App\Http\Requests\Catalog\UpdateProductCatalogItemRequest;
use App\Http\Resources\ProductCatalogItemResource;
use App\Models\ProductCatalogItem;
use App\Services\Catalog\ProductCatalogItemService;
use App\Support\DeletionGuard;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// ADR-036 §2/§5/§6 — global (no company_id) Super-Admin-write resource,
// same shape as CatalogBrandController/CompanyController. This is the
// "edit the shared item itself" surface (TASK-214); linking a company's
// own product TO an item is a separate action (ProductCatalogLinkController).
class ProductCatalogItemController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ProductCatalogItem::class, 'product_catalog_item');
    }

    public function index(): AnonymousResourceCollection
    {
        return ProductCatalogItemResource::collection(
            ProductCatalogItem::query()
                ->with(['catalogBrand', 'catalogCategory'])
                ->withCount('products')
                ->orderBy('name')
                ->paginate()
        );
    }

    public function store(StoreProductCatalogItemRequest $request, ProductCatalogItemService $service): ProductCatalogItemResource
    {
        $item = $service->create($request->validated());

        return new ProductCatalogItemResource($item->load(['catalogBrand', 'catalogCategory']));
    }

    public function show(ProductCatalogItem $productCatalogItem): ProductCatalogItemResource
    {
        return new ProductCatalogItemResource(
            $productCatalogItem->load(['catalogBrand', 'catalogCategory', 'media', 'specs'])->loadCount('products')
        );
    }

    public function update(UpdateProductCatalogItemRequest $request, ProductCatalogItem $productCatalogItem, ProductCatalogItemService $service): ProductCatalogItemResource
    {
        $item = $service->update($productCatalogItem, $request->validated());

        return new ProductCatalogItemResource($item->load(['catalogBrand', 'catalogCategory'])->loadCount('products'));
    }

    public function destroy(ProductCatalogItem $productCatalogItem): Response
    {
        // ADR-036 §3 — restrictOnDelete on products.catalog_item_id means
        // the DB would already refuse this; DeletionGuard turns that into
        // a clean 422 with a count instead of a raw constraint error, and
        // tells Super Admin exactly how many companies to unlink first.
        DeletionGuard::ensureNoDependents([
            'สินค้าที่เชื่อมกับรายการนี้ (ทุกบริษัท)' => $productCatalogItem->products()->withoutGlobalScopes()->count(),
        ]);

        $productCatalogItem->delete();

        return response()->noContent();
    }
}
