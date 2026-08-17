<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionRateType;
use App\Models\CommissionOverrideRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// TASK-025 / ADR-006: rate_value is always an integer (basis points for
// "percentage", satang for "fixed_satang" — BR-3). manager_cert_tier_id
// is global (cert_tiers has no company_id) so no company check needed
// there, same as CommissionRule's cert_tier_id. Overlap-with-existing-
// rule validation lives in CommissionOverrideRuleService, not here — it
// needs a query, not just input shape (mirrors StoreCommissionRuleRequest).
class StoreCommissionOverrideRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CommissionOverrideRule::class);
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
            'manager_cert_tier_id' => ['required', 'integer', 'exists:cert_tiers,id'],
            'rate_type' => ['required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['required', 'integer', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
