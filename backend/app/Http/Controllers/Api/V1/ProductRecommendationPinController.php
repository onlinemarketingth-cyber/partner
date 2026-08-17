<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRecommendationPinRequest;
use App\Http\Requests\Catalog\UpdateProductRecommendationPinRequest;
use App\Http\Resources\ProductRecommendationPinResource;
use App\Models\ProductRecommendationPin;
use App\Services\Catalog\ProductRecommendationPinService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// TASK-068 / ADR-020 row 4 — Admin CRUD for the manual pin list (feeds
// GET /products/recommended's auto-fill-topped-up assembly, see
// ProductRecommendationService). Read is open to any authenticated
// company member (same visibility rule as ProductCategoryPolicy), write
// is Company Admin/Super Admin only.
class ProductRecommendationPinController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ProductRecommendationPin::class, 'product_recommendation_pin');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ProductRecommendationPin::query()
            ->with('product')
            ->orderBy('sort_order');

        // TASK-156 §3 — "ปิดการใช้งาน ซ่อนทุกที่" (human, 2026-08-10). Rule for
        // an Agent, unchanged opt-in filter for the Admin who owns the toggle.
        //
        // This is the PIN's own is_active. Whether a pin pointing at a
        // deactivated PRODUCT should also drop out of this list is a separate
        // question and is left alone here: the row an Agent actually sees —
        // GET /products/recommended — already excludes inactive products on
        // both its pinned and its auto-fill half (§2.3), so the "cannot be
        // recommended" half of the decision is enforced there, not on this
        // Admin CRUD list. Flagged for ag-lead rather than guessed at.
        if ($request->user()?->isAgent()) {
            $query->where('is_active', true);
        } elseif ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return ProductRecommendationPinResource::collection($query->get());
    }

    public function store(StoreProductRecommendationPinRequest $request, ProductRecommendationPinService $service): ProductRecommendationPinResource
    {
        $pin = $service->create($request->validated(), $request->user());

        return new ProductRecommendationPinResource($pin->load('product'));
    }

    public function show(ProductRecommendationPin $productRecommendationPin): ProductRecommendationPinResource
    {
        return new ProductRecommendationPinResource($productRecommendationPin->load('product'));
    }

    public function update(UpdateProductRecommendationPinRequest $request, ProductRecommendationPin $productRecommendationPin, ProductRecommendationPinService $service): ProductRecommendationPinResource
    {
        $pin = $service->update($productRecommendationPin, $request->validated());

        return new ProductRecommendationPinResource($pin->load('product'));
    }

    public function destroy(ProductRecommendationPin $productRecommendationPin): Response
    {
        $productRecommendationPin->delete();

        return response()->noContent();
    }
}
