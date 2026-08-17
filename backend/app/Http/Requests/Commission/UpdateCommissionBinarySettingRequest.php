<?php

namespace App\Http\Requests\Commission;

use App\Enums\Ability;
use App\Enums\BinaryCycleFrequency;
use App\Enums\CommissionRateType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-011/TASK-029 — Company Admin (own company) / Super Admin only,
// same visibility shape as UpdateVideoProcessingSettingRequest /
// CommissionRule management. Every value here is BR-7 (matched rate,
// cycle cadence, payout cap, carry-over policy) — none defaulted or
// assumed by ag-lead; an admin must explicitly configure this before
// BinaryCommissionService::runDueCycles() will ever process this
// company (see that Service's own docblock).
class UpdateCommissionBinarySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Ability::SettingsCommissionBinaryUpdate);
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
            'matched_rate_type' => ['required', Rule::enum(CommissionRateType::class)],
            'matched_rate_value' => ['required', 'integer', 'min:0'],
            'cycle_frequency' => ['required', Rule::enum(BinaryCycleFrequency::class)],
            'payout_cap_satang' => ['nullable', 'integer', 'min:0'], // null = uncapped (BR-7)
            'carry_over_unmatched' => ['sometimes', 'boolean'],
        ];
    }
}
