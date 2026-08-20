<?php

namespace App\Services\Catalog;

use App\Models\CatalogCategory;

// Mirrors CatalogBrandService — see its docblock for the reasoning.
class CatalogCategoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CatalogCategory
    {
        return CatalogCategory::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CatalogCategory $catalogCategory, array $data): CatalogCategory
    {
        $catalogCategory->update($data);

        return $catalogCategory;
    }
}
