<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductSpec;

class ProductSpecService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(Product $product, array $data): ProductSpec
    {
        return ProductSpec::create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'spec_group' => $data['spec_group'] ?? null,
            'spec_key' => $data['spec_key'],
            'spec_value' => $data['spec_value'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductSpec $spec, array $data): ProductSpec
    {
        $spec->update($data);

        return $spec;
    }

    public function delete(ProductSpec $spec): void
    {
        $spec->delete();
    }
}
