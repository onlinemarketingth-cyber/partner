<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use App\Enums\AgentRankRecalculationFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-011/TASK-031 — Company Admin (own company) / Super Admin only,
// same shape as UpdateCommissionMatrixSettingRequest.
// trailing_window_days is BR-7 (never defaulted here);
// recalculation_frequency is a fixed vocabulary.
class UpdateAgentRankSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsAgentRankUpdate);
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
            'trailing_window_days' => ['required', 'integer', 'min:1', 'max:3650'], // sanity ceiling only, not a BR-7 value
            'recalculation_frequency' => ['required', Rule::enum(AgentRankRecalculationFrequency::class)],
        ];
    }
}
