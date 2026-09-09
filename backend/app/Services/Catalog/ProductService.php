<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// BR-3: price_satang is validated as a non-negative integer upstream
// (StoreProductRequest) — this Service never touches it as a float.
class ProductService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Product
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

        return Product::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        // ADR-036 §3 (TASK-212) — once a product is catalog-linked, its
        // own name/brand_id/category_id/description/spec_description are
        // vestigial: the shared catalog item owns them (see
        // Product::effectiveName() and its siblings). This generic update
        // path (StoreProductRequest/UpdateProductRequest — Company
        // Admin/Super Admin editing price, commission config, etc.) must
        // never let a stale value from the client's form state silently
        // overwrite or resurrect local identity data that is no longer
        // the source of truth. Setting/clearing catalog_item_id itself
        // only ever happens through ProductCatalogLinkService::link()/
        // unlink() (Super-Admin-only, TASK-213) — never here.
        if ($product->catalog_item_id !== null) {
            unset($data['name'], $data['brand_id'], $data['category_id'], $data['description'], $data['spec_description']);
        }

        $product->update($data);

        return $product;
    }
}
