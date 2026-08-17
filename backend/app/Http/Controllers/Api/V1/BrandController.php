<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreBrandRequest;
use App\Http\Requests\Catalog\UpdateBrandRequest;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Services\Catalog\BrandService;
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
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Brand::query()->orderBy('name');

        if ($request->user()?->isAgent()) {
            $query->where('is_active', true);
        }

        return BrandResource::collection($query->paginate());
    }

    public function store(StoreBrandRequest $request, BrandService $service): BrandResource
    {
        $brand = $service->create($request->validated(), $request->user());

        return new BrandResource($brand);
    }

    public function show(Brand $brand): BrandResource
    {
        return new BrandResource($brand);
    }

    public function update(UpdateBrandRequest $request, Brand $brand, BrandService $service): BrandResource
    {
        return new BrandResource($service->update($brand, $request->validated()));
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
