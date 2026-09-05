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
            /*
             * TASK-251 — REQUIRED, unlike the nullable column behind it.
             *
             * Saving this form now creates a listing in every company
             * (disabled, at this price). There is no honest way to do that
             * without a number: 0 บาท is a claim, not a blank, and BR-7 is
             * exactly the rule against inventing one. The column stays
             * nullable for rows that predate this rule; new items must say.
             *
             * BR-3 — satang. The screen sends baht x 100.
             */
            'default_price_satang' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The default English message names the column, not the thing —
            // "The default price satang field is required" is not a sentence
            // the Super Admin filling this form can act on. It also has to
            // say WHY the field is suddenly mandatory, because it was not
            // before TASK-251.
            'default_price_satang.required' => 'ต้องระบุราคาเริ่มต้น เพราะการบันทึกจะเพิ่มสินค้านี้ให้ทุกบริษัท (ปิดการใช้งานไว้) และแต่ละบริษัทแก้ราคาของตัวเองได้ภายหลัง',
            'default_price_satang.integer' => 'ราคาเริ่มต้นไม่ถูกต้อง',
            'default_price_satang.min' => 'ราคาเริ่มต้นต้องไม่ติดลบ',
        ];
    }
}
