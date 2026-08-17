<?php

namespace App\Http\Requests\Commission;

use App\Enums\CommissionRateType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentRankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('agent_rank'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'volume_threshold' => ['sometimes', 'required', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            'rate_type' => ['sometimes', 'required', Rule::enum(CommissionRateType::class)],
            'rate_value' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_breakaway_rank' => ['sometimes', 'boolean'],
        ];
    }
}
