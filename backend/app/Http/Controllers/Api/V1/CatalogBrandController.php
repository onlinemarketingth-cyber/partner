<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCatalogBrandRequest;
use App\Http\Requests\Catalog\UpdateCatalogBrandRequest;
use App\Http\Resources\CatalogBrandResource;
use App\Models\CatalogBrand;
use App\Services\Catalog\CatalogBrandService;
use App\Support\DeletionGuard;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// ADR-036 §2/§5 — global (no company_id, no TenantScope) resource, same
// shape as CompanyController: Policy is the ONLY gate (viewAny/view true
// for anyone, create/update/delete Super-Admin-only via CatalogBrandPolicy)
// — no route-model-binding tenant check applies because there is no
// tenant to check.
class CatalogBrandController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CatalogBrand::class, 'catalog_brand');
    }

    public function index(): AnonymousResourceCollection
    {
        return CatalogBrandResource::collection(CatalogBrand::query()->orderBy('name')->paginate());
    }

    public function store(StoreCatalogBrandRequest $request, CatalogBrandService $service): CatalogBrandResource
    {
        return new CatalogBrandResource($service->create($request->validated()));
    }

    public function show(CatalogBrand $catalogBrand): CatalogBrandResource
    {
        return new CatalogBrandResource($catalogBrand);
    }

    public function update(UpdateCatalogBrandRequest $request, CatalogBrand $catalogBrand, CatalogBrandService $service): CatalogBrandResource
    {
        return new CatalogBrandResource($service->update($catalogBrand, $request->validated()));
    }

    public function destroy(CatalogBrand $catalogBrand): Response
    {
        // ADR-036 §2 — restrictOnDelete on product_catalog_items.catalog_brand_id
        // would throw a raw DB constraint error without this check; same
        // "guard before the FK does" reasoning as BrandController.
        DeletionGuard::ensureNoDependents([
            'สินค้าในแคตตาล็อกกลาง' => $catalogBrand->catalogItems()->count(),
        ]);

        $catalogBrand->delete();

        return response()->noContent();
    }
}
