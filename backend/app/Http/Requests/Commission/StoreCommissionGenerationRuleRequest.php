<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionRateType;
use App\Models\CommissionGenerationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-011/TASK-031: rate_value is always an integer (BR-3). generation_number
// is not cross-checked against commission_generation_settings.max_generation_depth
// here — same "harmless, not worth a chicken-and-egg ordering requirement"
// reasoning as StoreCommissionMatrixLevelRateRequest. Overlap validation
// lives in CommissionGenerationRuleService.
class StoreCommissionGenerationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CommissionGenerationRule::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [Rule::requiredIf(fn () => $this->user()->isSuperAdmin()), 'integer', 'exists:companies,id'],
            'generation_number' => ['required', 'integer', 'min:1', 'max:50'], // sanity ceiling only, not a BR-7 value
            'rate_type' => ['required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['required', 'integer', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
