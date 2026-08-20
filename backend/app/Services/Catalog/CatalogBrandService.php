<?php

namespace App\Services\Catalog;

use App\Models\CatalogBrand;

// ADR-036 §2 — no company scoping (unlike Brand/ProductService): there is
// no company_id to stamp, authorization is Super-Admin-only at the
// Policy layer (CatalogBrandPolicy), enforced before this Service is ever
// called (Form Request authorize(), mirroring every other *Service in
// this namespace).
class CatalogBrandService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CatalogBrand
    {
        return CatalogBrand::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CatalogBrand $catalogBrand, array $data): CatalogBrand
    {
        $catalogBrand->update($data);

        return $catalogBrand;
    }
}
