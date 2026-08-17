<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionRateType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCommissionOverrideRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('commission_override_rule'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rate_type' => ['sometimes', 'required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['sometimes', 'required', 'integer', 'min:0'],
            'effective_from' => ['sometimes', 'required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
