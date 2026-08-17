<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Section 6: Form Requests validate every input, never trust the client.
// company_id is deliberately NOT a validated field here — it's injected
// server-side in BrandService::create() from the authenticated user
// (or, for Super Admin only, an explicit company_id — see the Service).
class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Brand::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
