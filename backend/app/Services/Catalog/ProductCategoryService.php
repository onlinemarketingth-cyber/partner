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
        /*
         * 2026-09-09 — NULL is now a real answer: the platform owns the row and
         * every company uses it (ADR-040). It is only a real answer for a SUPER
         * ADMIN, though, and this is the second place that is checked — the
         * Form Request guards one route, whereas a row created here is visible
         * to every tenant on the system.
         */
        // (bool), not === true: the `boolean` rule accepts 1/0/"1"/"0" and
        // validated() hands them back unchanged.
        $platform = (bool) ($data['is_platform'] ?? false) && $actor->isSuperAdmin();
        unset($data['is_platform']);

        $companyId = $platform
            ? null
            : ($actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id);

        if (! $platform && $companyId === null) {
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
