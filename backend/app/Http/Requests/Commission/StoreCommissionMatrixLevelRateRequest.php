<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionRateType;
use App\Models\CommissionMatrixLevelRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ADR-011/TASK-030: rate_value is always an integer (basis points for
// "percentage", satang for "fixed_satang" — BR-3). level is not
// cross-checked against commission_matrix_settings.depth here (a rate
// for a level beyond the configured depth is simply never reached by
// MatrixCommissionService::payDownlineOverrides()'s own cap — harmless,
// not worth a chicken-and-egg ordering requirement between the two
// configs). Overlap validation lives in CommissionMatrixLevelRateService.
class StoreCommissionMatrixLevelRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CommissionMatrixLevelRate::class);
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
            'level' => ['required', 'integer', 'min:1', 'max:50'], // sanity ceiling only, not a BR-7 value
            'rate_type' => ['required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['required', 'integer', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
