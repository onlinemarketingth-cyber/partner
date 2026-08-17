<?php

namespace App\Http\Requests\Engagement;

use App\Enums\RewardType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRewardItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('reward_item'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost_points' => ['sometimes', 'integer', 'min:1'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'reward_type' => ['sometimes', Rule::enum(RewardType::class)],
        ];
    }
}
