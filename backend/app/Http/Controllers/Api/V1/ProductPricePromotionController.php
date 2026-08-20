<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\StoreProductPricePromotionRequest;
use App\Http\Requests\Engagement\UpdateProductPricePromotionRequest;
use App\Http\Resources\ProductPricePromotionResource;
use App\Models\ProductPricePromotion;
use App\Services\Engagement\ProductPricePromotionService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// Product-view IA item 2.3b. Display-only prototype — see model/migration
// comment for the flagged commission-calc question.
class ProductPricePromotionController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ProductPricePromotion::class, 'product_price_promotion');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ProductPricePromotion::with('product');
        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        // TASK-156 §3 — "ปิดการใช้งาน ซ่อนทุกที่" (human, 2026-08-10), and the
        // one item in the audit ag-lead said it would raise first.
        //
        // This list filtered on `product_id` and nothing else, so an Agent
        // could read UNANNOUNCED FUTURE PRICE CUTS (status=draft, or a window
        // that opens next quarter) and expired promo pricing. That is
        // commercially sensitive in a way a brand name is not, and unlike a
        // product it has no history argument: a promotion that has not started
        // has nothing to be historical about.
        //
        // The same SQL the model's own isCurrentlyActive() expresses in PHP —
        // see the scope's docblock for why both exist and why they are written
        // to mirror each other. Admins are exempt: they author the schedule.
        if ($request->user()?->isAgent()) {
            $query->currentlyActive();
        }

        return ProductPricePromotionResource::collection($query->latest()->get());
    }

    public function store(StoreProductPricePromotionRequest $request, ProductPricePromotionService $service): ProductPricePromotionResource
    {
        $promotion = $service->create($request->validated(), $request->user());

        return new ProductPricePromotionResource($promotion->load('product'));
    }

    public function show(Request $request, ProductPricePromotion $productPricePromotion): ProductPricePromotionResource
    {
        /*
         * TASK-156 §2 — the same detail-route gap as Announcement and
         * AgentPromotion, flagged by ag-dev and missing from the spec table.
         *
         * ProductPricePromotionPolicy::view() returns true for everyone in the
         * company, so an Agent who guessed an id could read an UNANNOUNCED
         * FUTURE PRICE CUT — the discount and the date it starts — while
         * index() went to the trouble of hiding it.
         *
         * `isCurrentlyActive()` rather than the scope: this is one row already
         * in memory, which is exactly the split the model's docblock draws
         * between the two halves.
         *
         * 404, not 403 — a promotion that has not started has no existence a
         * customer-facing agent is entitled to infer.
         */
        if ($request->user()?->isAgent()) {
            abort_unless($productPricePromotion->isCurrentlyActive(), 404);
        }

        return new ProductPricePromotionResource($productPricePromotion->load('product'));
    }

    public function update(UpdateProductPricePromotionRequest $request, ProductPricePromotion $productPricePromotion, ProductPricePromotionService $service): ProductPricePromotionResource
    {
        $promotion = $service->update($productPricePromotion, $request->validated());

        return new ProductPricePromotionResource($promotion->load('product'));
    }

    public function destroy(ProductPricePromotion $productPricePromotion, ProductPricePromotionService $service): Response
    {
        $service->delete($productPricePromotion);

        return response()->noContent();
    }
}
