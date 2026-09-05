<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product_catalog_item'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'catalog_brand_id' => ['sometimes', 'required', 'integer', 'exists:catalog_brands,id'],
            'catalog_category_id' => ['sometimes', 'required', 'integer', 'exists:catalog_categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'spec_description' => ['nullable', 'string'],
            /*
             * TASK-251 — editable, and deliberately WITHOUT any effect on
             * companies that already have this item.
             *
             * This value is only ever read at the moment a company's copy is
             * created (a new company, or a company added later). Changing it
             * changes what the NEXT company starts from; it does not reach
             * back and reprice anybody, because by then that price belongs to
             * that company (ADR-036 §3) and the human's decision was
             * explicitly "มาแก้ไขแยกบริษัทได้".
             */
            'default_price_satang' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
