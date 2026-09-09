<?php

namespace App\Http\Requests\Catalog;

use App\Models\ProductCategory;
use App\Support\CuratedIcons;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductCategoryRequest extends FormRequest
{
    use Concerns\ChoosesPlatformOrCompany;

    protected function prepareForValidation(): void
    {
        $this->stripPlatformFlagUnlessSuperAdmin();
    }

    public function authorize(): bool
    {
        return $this->user()->can('create', ProductCategory::class);
    }

    /**
     * The company this category will belong to — same shape as
     * StoreProductRequest::effectiveCompanyId(): Super Admin supplies it,
     * everyone else is forced to their own regardless of what is sent.
     */
    protected function effectiveCompanyId(): ?int
    {
        return $this->ownerCompanyId();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->ownershipRules() + [
            'name' => ['required', 'string', 'max:255'],
            // TASK-068 / ADR-020 row 3 — same whitelist as UpdateProductCategoryRequest.
            'icon' => ['sometimes', 'nullable', Rule::in(CuratedIcons::WHITELIST)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            // ADR-026 §3.3 (TASK-132) — middle scope. Null/omitted =
            // inherit the company default. BR-6: same company only.
            'pipeline_template_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pipeline_templates', 'id')->where('company_id', $this->effectiveCompanyId())],
        ];
    }
}
