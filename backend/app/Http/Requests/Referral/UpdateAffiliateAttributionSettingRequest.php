<?php

namespace App\Http\Requests\Referral;

use App\Enums\Ability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-011/TASK-032 — Company Admin (own company) / Super Admin only,
// same shape as UpdateAgentRankSettingRequest. attribution_window_days
// is BR-7, never defaulted here.
class UpdateAffiliateAttributionSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsAffiliateAttributionUpdate);
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
            'attribution_window_days' => ['required', 'integer', 'min:1', 'max:3650'], // sanity ceiling only, not a BR-7 value
            'new_vs_returning_rate_differential_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
