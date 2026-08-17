<?php

namespace App\Http\Requests\Engagement;

use App\Enums\RewardType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRewardItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\RewardItem::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin()),
                'nullable',
                'integer',
                Rule::exists('companies', 'id'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost_points' => ['required', 'integer', 'min:1'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            // TASK-042 §2: default 'physical' matches the migration
            // column default — omitting this on create still yields a
            // physical item.
            'reward_type' => ['nullable', Rule::enum(RewardType::class)],
        ];
    }
}
