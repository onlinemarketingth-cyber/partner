<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductSpecRequest;
use App\Http\Requests\Catalog\UpdateProductSpecRequest;
use App\Http\Resources\ProductSpecResource;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Services\Catalog\ProductSpecService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// ADR-007 — no dedicated Policy, reuses ProductPolicy (same shape as
// ProductMediaController/ProductSalesMaterialController).
class ProductSpecController extends Controller
{
    public function index(Product $product): AnonymousResourceCollection
    {
        $this->authorize('view', $product);

        return ProductSpecResource::collection($product->specs);
    }

    public function store(StoreProductSpecRequest $request, Product $product, ProductSpecService $service): ProductSpecResource
    {
        return new ProductSpecResource($service->store($product, $request->validated()));
    }

    public function update(UpdateProductSpecRequest $request, ProductSpec $productSpec, ProductSpecService $service): ProductSpecResource
    {
        return new ProductSpecResource($service->update($productSpec, $request->validated()));
    }

    public function destroy(ProductSpec $productSpec, ProductSpecService $service): Response
    {
        $this->authorize('update', $productSpec->product);

        $service->delete($productSpec);

        return response()->noContent();
    }
}
