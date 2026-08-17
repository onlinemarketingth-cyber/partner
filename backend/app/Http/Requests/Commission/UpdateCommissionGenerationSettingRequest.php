<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-011/TASK-031 — Company Admin (own company) / Super Admin only,
// same shape as UpdateCommissionMatrixSettingRequest.
// max_generation_depth is BR-7, never defaulted here.
class UpdateCommissionGenerationSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsCommissionGenerationUpdate);
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
        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            'max_generation_depth' => ['required', 'integer', 'min:1', 'max:50'], // sanity ceiling only, not a BR-7 value
        ];
    }
}
