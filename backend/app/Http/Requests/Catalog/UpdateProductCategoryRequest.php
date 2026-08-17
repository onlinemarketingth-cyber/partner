<?php

namespace App\Http\Requests\Catalog;

use App\Support\CuratedIcons;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product_category'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\ProductCategory $category */
        $category = $this->route('product_category');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // TASK-068 / ADR-020 row 3 — server-side whitelist (unlike
            // nav_icon_overrides, deliberately validated here since this
            // renders directly on the public-facing storefront). null
            // clears the icon back to "none chosen".
            'icon' => ['sometimes', 'nullable', Rule::in(CuratedIcons::WHITELIST)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            // ADR-026 §3.3 (TASK-132) — explicit null clears the
            // category-level journey back to "inherit the company
            // default". BR-6: same company only.
            'pipeline_template_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pipeline_templates', 'id')->where('company_id', $category->company_id)],
        ];
    }
}
