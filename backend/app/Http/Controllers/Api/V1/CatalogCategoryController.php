<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCatalogCategoryRequest;
use App\Http\Requests\Catalog\UpdateCatalogCategoryRequest;
use App\Http\Resources\CatalogCategoryResource;
use App\Models\CatalogCategory;
use App\Services\Catalog\CatalogCategoryService;
use App\Support\DeletionGuard;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// Mirrors CatalogBrandController — see its docblock for the reasoning.
class CatalogCategoryController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(CatalogCategory::class, 'catalog_category');
    }

    public function index(): AnonymousResourceCollection
    {
        return CatalogCategoryResource::collection(
            CatalogCategory::query()->orderBy('sort_order')->orderBy('name')->paginate()
        );
    }

    public function store(StoreCatalogCategoryRequest $request, CatalogCategoryService $service): CatalogCategoryResource
    {
        return new CatalogCategoryResource($service->create($request->validated()));
    }

    public function show(CatalogCategory $catalogCategory): CatalogCategoryResource
    {
        return new CatalogCategoryResource($catalogCategory);
    }

    public function update(UpdateCatalogCategoryRequest $request, CatalogCategory $catalogCategory, CatalogCategoryService $service): CatalogCategoryResource
    {
        return new CatalogCategoryResource($service->update($catalogCategory, $request->validated()));
    }

    public function destroy(CatalogCategory $catalogCategory): Response
    {
        DeletionGuard::ensureNoDependents([
            'สินค้าในแคตตาล็อกกลาง' => $catalogCategory->catalogItems()->count(),
        ]);

        $catalogCategory->delete();

        return response()->noContent();
    }
}
