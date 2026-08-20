<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Services\Catalog\BrandService;
use App\Support\CompanyScopeFilter;
use App\Support\DeletionGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// Section 7: thin controller. Route-model-binding on {brand} resolves
// through Brand's TenantScope, so a cross-tenant ID 404s before the
// Policy even runs (BR-6 rule 5); authorizeResource() is the second,
// explicit layer for defense-in-depth.
class BrandController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Brand::class, 'brand');
    }

    /**
     * TASK-156 §3 — "ปิดการใช้งาน ซ่อนทุกที่" (human, 2026-08-10).
     *
     * `is_active` used to be a field the Resource exposed and nothing ever
     * filtered on: the Agent Portal hid deactivated brands client-side, which
     * is decoration, not hiding. A brand is a browse/filter facet — pure
     * discovery — so it has none of the history argument that keeps a
     * deactivated PRODUCT resolvable on an old commission row (§3 boundary).
     *
     * Admins are exempt: they are the ones toggling the flag and need to find
     * what they switched off. Same split as ModuleController::visibleTo()
     * (TASK-155).
     */
    /**
     * TASK-202 (human-reported 2026-08-19) — two fixes on one line.
     *
     * 1. `paginate()` -> `get()`. A brand list is a reference/lookup list:
     *    every consumer (ProductCatalogView's manage drawer, the product
     *    form's brand <select>, the Agent Portal's filter chips) reads
     *    `data` and renders all of it — none of them has ever rendered a
     *    pager. With the default 15-per-page that meant brand #16 onward
     *    silently did not exist in the UI, which for a Super Admin (whose
     *    list spans EVERY company, since TenantScope does not narrow them)
     *    is reachable with only a handful of companies. Matches
     *    ProductCategoryController::index(), which already uses get().
     * 2. withCount('products') feeds the "ใช้กับสินค้า N" column so an
     *    admin can see before clicking delete whether DeletionGuard will
     *    refuse (products.brand_id is restrictOnDelete). Counted, never
     *    loaded — one extra sub-select, no N+1.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Brand::query()->withCount('products')->orderBy('name');

        // TASK-209 — Super Admin's header company scope, applied in SQL.
        CompanyScopeFilter::apply($query, $request);

        if ($request->user()?->isAgent()) {
            $query->where('is_active', true);
        }

        return BrandResource::collection($query->get());
    }

    // TASK-205 — the uploaded logo travels as a file, not a validated
    // scalar, so it is handed to the Service separately (identical shape to
    // StorefrontBannerController::store/update).
    public function store(StoreBrandRequest $request, BrandService $service): BrandResource
    {
        $brand = $service->create($request->validated(), $request->user(), $request->file('logo'));

        return new BrandResource($brand);
    }

    public function show(Brand $brand): BrandResource
    {
        return new BrandResource($brand);
    }

    public function update(UpdateBrandRequest $request, Brand $brand, BrandService $service): BrandResource
    {
        return new BrandResource($service->update($brand, $request->validated(), $request->file('logo')));
    }

    public function destroy(Brand $brand): Response
    {
        // TASK-091 — soft delete never trips products.brand_id's
        // restrictOnDelete FK, so the check has to live here.
        DeletionGuard::ensureNoDependents([
            'สินค้า' => $brand->products()->count(),
        ]);

        $brand->delete();

        return response()->noContent();
    }
}
