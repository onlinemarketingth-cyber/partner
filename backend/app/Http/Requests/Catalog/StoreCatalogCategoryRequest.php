<?php

namespace App\Http\Requests\Catalog;

use App\Models\CatalogCategory;
use App\Support\CuratedIcons;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCatalogCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CatalogCategory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // TASK-068 / ADR-020 row 3 — same curated-icon-whitelist
            // convention as StoreProductCategoryRequest.
            'icon' => ['sometimes', 'nullable', Rule::in(CuratedIcons::WHITELIST)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
