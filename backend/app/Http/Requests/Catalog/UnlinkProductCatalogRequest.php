<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-036 §3/§6 — DELETE /products/{product}/catalog-link. Same
// Super-Admin-only + ProductPolicy::update AND gate as
// LinkProductCatalogRequest — see its docblock. The caller MUST supply a
// full local identity (name/brand_id/category_id) since the product goes
// back to being standalone the instant this runs, and standalone
// products still require all three (the DB migration only relaxed the
// COLUMN constraint, not the business rule — see
// 2026_08_18_120600_make_brand_category_name_nullable_on_products_table's
// own docblock).
class UnlinkProductCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $product !== null
            && $this->user()->isSuperAdmin()
            && $this->user()->can('update', $product);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\Product $product */
        $product = $this->route('product');
        $companyId = $product->company_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            // BR-6 — scoped to the product's own company, exactly like
            // StoreProductRequest's brand_id/category_id rules.
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')->where('company_id', $companyId)],
            'category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->where('company_id', $companyId)],
            'description' => ['nullable', 'string'],
            'spec_description' => ['nullable', 'string'],
        ];
    }
}
