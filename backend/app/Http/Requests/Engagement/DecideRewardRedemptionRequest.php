<?php

namespace App\Http\Requests\Engagement;

use App\Enums\RedemptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideRewardRedemptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decide', $this->route('reward_redemption'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([RedemptionStatus::Approved->value, RedemptionStatus::Rejected->value, RedemptionStatus::Fulfilled->value])],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
