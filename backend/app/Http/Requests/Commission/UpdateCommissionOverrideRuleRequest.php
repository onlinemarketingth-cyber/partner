<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionRateType;
use App\Http\Requests\Catalog\Concerns\ValidatesProductOwnership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCommissionOverrideRuleRequest extends FormRequest
{
    use ValidatesProductOwnership;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('commission_override_rule'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // TASK-214 — the scope is editable on update, same as the agent
        // rate's. company_id is not: a rule never changes tenant (BR-6).
        $companyId = $this->route('commission_override_rule')?->company_id;

        return [
            'product_id' => [
                'sometimes', 'nullable', 'integer',
                $this->configurableProductRule($companyId),
                Rule::prohibitedIf(fn () => $this->filled('product_category_id')),
            ],
            'product_category_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('product_categories', 'id')->where('company_id', $companyId),
                Rule::prohibitedIf(fn () => $this->filled('product_id')),
            ],
            'manager_cert_tier_id' => ['sometimes', 'nullable', 'integer', 'exists:cert_tiers,id'],
            'rate_type' => ['sometimes', 'required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['sometimes', 'required', 'integer', 'min:0'],
            // Sending null CLEARS the rate's own mode and puts it back on the
            // company's — see the store request for why that is a real value.
            'override_mode' => ['sometimes', 'nullable', Rule::enum(CommissionOverrideMode::class)],
            'effective_from' => ['sometimes', 'required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
