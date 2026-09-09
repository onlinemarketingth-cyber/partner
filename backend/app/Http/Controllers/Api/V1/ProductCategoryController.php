<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductCategoryRequest;
use App\Http\Requests\Catalog\UpdateProductCategoryRequest;
use App\Http\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use App\Services\Catalog\ProductCategoryService;
use App\Support\Catalog\DeletionImpact;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductCategoryController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ProductCategory::class, 'product_category');
    }

    // TASK-071 QA follow-up (ADR-020) — was ->paginate() (default 15/page),
    // which silently truncated the Agent Portal's storefront row 3 (all
    // active categories, no pagination UI/awareness on that consumer) past
    // 15 categories. Every caller of this endpoint (ProductCatalogView.vue,
    // ProductEditView.vue, CommissionPlansView.vue, and the new
    // ProductBrowseView.vue) already just reads `res.data` with no
    // pagination meta/links usage, so switching to ->get() is a safe,
    // behavior-preserving fix for all of them — same "no pagination, small
    // fixed set" shape as the sibling StorefrontBannerController/
    // ProductRecommendationPinController::index() already use.
    //
    // TASK-156 §3 — "ปิดการใช้งาน ซ่อนทุกที่" (human, 2026-08-10). Same
    // reasoning and same Admin exemption as BrandController::index(): a
    // category is a browse facet, nothing but discovery hangs off it, and
    // storefront row 3 was already filtering it client-side — which hid
    // nothing.
    public function index(Request $request): AnonymousResourceCollection
    {
        // TASK-202 — withCount feeds the "ใช้กับสินค้า N" column in the
        // manage dialog, same purpose and same cost as
        // BrandController::index()'s (products.category_id is likewise
        // restrictOnDelete, so the count is what tells an admin in advance
        // that DeletionGuard will refuse).
        $query = ProductCategory::query()->withCount('products')->orderBy('sort_order')->orderBy('name');

        // TASK-209 — Super Admin's header company scope, applied in SQL.
        /*
         * TASK-253 / ADR-040 — includePlatformWide, because a NULL company_id
         * here now means "the platform owns this row and every company uses
         * it", exactly as it already did for announcements and reward items.
         *
         * Without it the picker would CONTRADICT the tenant scope: a Company
         * Admin (SharedOrTenantScope) sees the shared rows, while a Super
         * Admin who narrows to that same company does not — so the person
         * with more authority sees less, and would reasonably conclude the
         * shared product is missing from that company.
         */
        CompanyScopeFilter::apply($query, $request, includePlatformWide: true);

        if ($request->user()?->isAgent()) {
            $query->where('is_active', true);
        }

        return ProductCategoryResource::collection($query->get());
    }

    public function store(StoreProductCategoryRequest $request, ProductCategoryService $service): ProductCategoryResource
    {
        return new ProductCategoryResource($service->create($request->validated(), $request->user()));
    }

    public function show(ProductCategory $productCategory): ProductCategoryResource
    {
        return new ProductCategoryResource($productCategory);
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $productCategory, ProductCategoryService $service): ProductCategoryResource
    {
        return new ProductCategoryResource($service->update($productCategory, $request->validated()));
    }

    /** GET /product-categories/{productCategory}/deletion-impact. */
    public function deletionImpact(ProductCategory $productCategory): JsonResponse
    {
        $this->authorize('delete', $productCategory);

        return response()->json(['data' => DeletionImpact::forCategory($productCategory)]);
    }

    public function destroy(ProductCategory $productCategory): Response
    {
        // TASK-091 — same reason as BrandController: soft delete bypasses
        // the restrictOnDelete FKs on products.category_id and
        // commission_rules.product_category_id.
        DeletionImpact::enforce(DeletionImpact::forCategory($productCategory));

        $productCategory->delete();

        return response()->noContent();
    }

    /** POST /product-categories/{productCategory}/restore — withTrashed(). */
    public function restore(ProductCategory $productCategory): ProductCategoryResource
    {
        $this->authorize('restore', $productCategory);

        $productCategory->restore();

        return new ProductCategoryResource($productCategory);
    }
}
