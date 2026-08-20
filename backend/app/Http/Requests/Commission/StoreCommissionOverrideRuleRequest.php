<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionRateType;
use App\Models\CommissionOverrideRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// rate_value is always an integer (basis points for "percentage", satang
// for "fixed_satang" — BR-3). Overlap-with-existing-rule validation lives
// in CommissionOverrideRuleService, not here — it needs a query, not just
// input shape (mirrors StoreCommissionRuleRequest).
//
// TASK-214 — the scope pair mirrors StoreCommissionRuleRequest exactly,
// including the mutual prohibition: at most ONE of product_id /
// product_category_id, both omitted = the company-wide default. Written
// as a copy of that rule set rather than an abstraction over it, because
// the two are the same TODAY by explicit decision, not by nature — if the
// leader rate ever needs a dimension the agent rate does not, a shared
// base class would be the thing standing in the way.
//
// manager_cert_tier_id is now `nullable` and is NOT read when resolving a
// payout (human ruling 2026-08-19: "ไม่ต้องผูก"). It is still accepted so
// an operator can annotate a legacy row while collapsing it; new rows
// simply omit it.
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
            'manager_cert_tier_id' => ['nullable', 'integer', 'exists:cert_tiers,id'],
            'product_id' => [
                'nullable', 'integer',
                Rule::exists('products', 'id')->where('company_id', $this->effectiveCompanyId()),
                Rule::prohibitedIf(fn () => $this->filled('product_category_id')),
            ],
            'product_category_id' => [
                'nullable', 'integer',
                Rule::exists('product_categories', 'id')->where('company_id', $this->effectiveCompanyId()),
                Rule::prohibitedIf(fn () => $this->filled('product_id')),
            ],
            'rate_type' => ['required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['required', 'integer', 'min:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
