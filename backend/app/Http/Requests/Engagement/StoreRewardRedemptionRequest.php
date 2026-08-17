<?php

namespace App\Http\Requests\Engagement;

use App\Enums\RewardType;
use App\Models\RewardItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Agent-initiated only — see RewardRedemptionPolicy::create(). Balance/
// stock checks happen in RewardRedemptionService (domain rules, not
// input shape), never here.
//
// TASK-042 §2: shipping_* fields are required_if the target
// RewardItem's reward_type === physical — same "look up the actual
// related model, don't trust a client-sent type" precedent as
// StoreCommissionRuleRequest's product_id/product_category_id mutual
// exclusion (Catalog namespace), except here the condition depends on
// resolving a *different* model (RewardItem) than the one being
// created (RewardRedemption), so a plain Rule::prohibitedIf/requiredIf
// on a sibling input field isn't enough — targetRewardType() below
// resolves and memoizes the actual reward_item row for the duration of
// validation.
class StoreRewardRedemptionRequest extends FormRequest
{
    private bool $targetRewardTypeResolved = false;

    private ?RewardType $targetRewardType = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\RewardRedemption::class);
    }

    /**
     * Resolves the RewardItem referenced by reward_item_id and returns
     * its reward_type — null if reward_item_id is missing/invalid (the
     * 'exists' rule on reward_item_id already flags that separately;
     * shipping_* simply stay optional in that case rather than piling
     * on a second, confusing error).
     */
    protected function targetRewardType(): ?RewardType
    {
        if (! $this->targetRewardTypeResolved) {
            $this->targetRewardTypeResolved = true;
            $rewardItemId = $this->input('reward_item_id');
            $this->targetRewardType = $rewardItemId
                ? RewardItem::find($rewardItemId)?->reward_type
                : null;
        }

        return $this->targetRewardType;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isPhysical = fn () => $this->targetRewardType() === RewardType::Physical;

        return [
            'reward_item_id' => ['required', 'integer', Rule::exists('reward_items', 'id')],
            'shipping_recipient_name' => [Rule::requiredIf($isPhysical), 'nullable', 'string', 'max:255'],
            'shipping_phone' => [Rule::requiredIf($isPhysical), 'nullable', 'string', 'max:50'],
            'shipping_address' => [Rule::requiredIf($isPhysical), 'nullable', 'string', 'max:2000'],
        ];
    }
}
