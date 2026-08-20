<?php

namespace App\Http\Requests\Catalog;

use App\Models\Brand;
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
        return $this->user()->can('create', Brand::class);
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
            // TASK-205 (human, 2026-08-19: "ผมต้องการเฉพาะแบรนด์มีการ upload
            // รูปแบรนด์ได้"). Same upload shape as
            // StoreStorefrontBannerRequest's 'image': a multipart file the
            // Service turns into logo_path. 2 MB rather than the banner's 5,
            // because a logo renders as a ~36px mark in a list, never
            // full-bleed. SVG is deliberately NOT accepted — an SVG is an
            // executable document (script/foreignObject) and these files are
            // served straight off the public disk (Section 6, XSS).
            'logo' => ['sometimes', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
