<?php

namespace App\Http\Requests\Engagement;

use App\Enums\CertTierTargetMode;
use App\Enums\CommissionRateType;
use App\Enums\PromotionPayoutTiming;
use App\Enums\PromotionTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\AgentPromotion::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => [
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin()),
                Rule::prohibitedIf(fn () => ! $this->user()->isSuperAdmin()),
                'integer',
                Rule::exists('companies', 'id'),
            ],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'target_type' => ['required', Rule::in(array_column(PromotionTargetType::cases(), 'value'))],
            'target_cert_tier_id' => ['required_if:target_type,cert_tier', 'nullable', 'integer', Rule::exists('cert_tiers', 'id')],
            'target_cert_tier_mode' => ['nullable', Rule::in(array_column(CertTierTargetMode::cases(), 'value'))],
            'target_agent_ids' => ['required_if:target_type,specific_agents', 'array'],
            'target_agent_ids.*' => ['integer', Rule::exists('users', 'id')],
            'bonus_type' => ['required', Rule::in(array_column(CommissionRateType::cases(), 'value'))],
            'bonus_value' => ['required', 'integer', 'min:1'],
            // TASK-042 §3 — no default (see the migration's own
            // docblock): required, must be chosen explicitly on every
            // create, never silently inferred.
            'payout_timing' => ['required', Rule::in(array_column(PromotionPayoutTiming::cases(), 'value'))],
            'status' => ['nullable', Rule::in(['draft', 'active', 'ended'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
