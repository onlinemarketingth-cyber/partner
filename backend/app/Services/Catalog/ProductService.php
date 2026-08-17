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
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
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
        $product->update($data);

        return $product;
    }
}
