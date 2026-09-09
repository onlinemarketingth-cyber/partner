<?php

namespace App\Http\Requests\Academy;

use App\Http\Requests\Catalog\Concerns\ValidatesProductOwnership;
use App\Models\Module;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-009 — Module is now a "Section": a pure grouping/ordering
// container. All content-item validation (video upload, mimes, embed
// URL, etc.) moved to StoreModuleLessonRequest.
class StoreModuleRequest extends FormRequest
{
    use ValidatesProductOwnership;

    public function authorize(): bool
    {
        return $this->user()->can('create', Module::class);
    }

    protected function effectiveCompanyId(): ?int
    {
        return $this->user()->isSuperAdmin()
            ? $this->integer('company_id') ?: null
            : $this->user()->company_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->effectiveCompanyId();

        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            'cert_tier_id' => ['required', 'integer', 'exists:cert_tiers,id'],
            'product_id' => ['nullable', 'integer', $this->configurableProductRule($companyId)],
            'title' => ['required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_published' => ['sometimes', 'boolean'],
            // ADR-031 §2.2 — see UpdateModuleRequest for the reasoning; the
            // two Requests are kept identical on purpose so a rule cannot be
            // enforced on edit but not on create.
            'enforce_sequential' => ['sometimes', 'boolean'],
            'drip_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }
}
