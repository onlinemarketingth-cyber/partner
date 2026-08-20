<?php

namespace App\Http\Requests\Catalog;

use App\Models\ProductCatalogItem;
use Illuminate\Foundation\Http\FormRequest;

// ADR-036 §2 — no company_id (global resource), unlike StoreProductRequest.
// catalog_brand_id/catalog_category_id validate against the equally
// global catalog_brands/catalog_categories tables — no ->where('company_id', ...)
// scoping needed or possible, since neither table has that column.
class StoreProductCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ProductCatalogItem::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'catalog_brand_id' => ['required', 'integer', 'exists:catalog_brands,id'],
            'catalog_category_id' => ['required', 'integer', 'exists:catalog_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'spec_description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
