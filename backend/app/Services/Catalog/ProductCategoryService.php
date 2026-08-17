<?php

namespace App\Services\Catalog;

use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ProductCategoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): ProductCategory
    {
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            // Defense-in-depth: the Form Request already requires company_id
            // for Super Admin, but the Service must never silently fall
            // through to a null tenant (BR-6) if that validation is ever
            // loosened.
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        $data['company_id'] = $companyId;

        return ProductCategory::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductCategory $productCategory, array $data): ProductCategory
    {
        $productCategory->update($data);

        return $productCategory;
    }
}
