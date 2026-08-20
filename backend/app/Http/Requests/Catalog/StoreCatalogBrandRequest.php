<?php

namespace App\Http\Requests\Catalog;

use App\Models\CatalogBrand;
use Illuminate\Foundation\Http\FormRequest;

// ADR-036 §2 — no company_id (global resource), unlike StoreBrandRequest.
class StoreCatalogBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CatalogBrand::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
