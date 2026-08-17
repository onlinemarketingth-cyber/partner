<?php

namespace App\Http\Requests\Engagement;

use App\Enums\CertTierTargetMode;
use App\Enums\CommissionRateType;
use App\Enums\PromotionPayoutTiming;
use App\Enums\PromotionTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// company_id is never editable — same "delete and recreate instead"
// reasoning as UpdateBadgeRequest.
class UpdateAgentPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('agent_promotion'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'target_type' => ['sometimes', Rule::in(array_column(PromotionTargetType::cases(), 'value'))],
            'target_cert_tier_id' => ['required_if:target_type,cert_tier', 'nullable', 'integer', Rule::exists('cert_tiers', 'id')],
            'target_cert_tier_mode' => ['nullable', Rule::in(array_column(CertTierTargetMode::cases(), 'value'))],
            'target_agent_ids' => ['required_if:target_type,specific_agents', 'array'],
            'target_agent_ids.*' => ['integer', Rule::exists('users', 'id')],
            'bonus_type' => ['sometimes', Rule::in(array_column(CommissionRateType::cases(), 'value'))],
            'bonus_value' => ['sometimes', 'integer', 'min:1'],
            'payout_timing' => ['sometimes', Rule::in(array_column(PromotionPayoutTiming::cases(), 'value'))],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'ended'])],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
