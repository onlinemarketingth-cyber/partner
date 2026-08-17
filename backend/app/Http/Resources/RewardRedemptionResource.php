<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RewardRedemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'user_id' => $this->user_id,
            'agent_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'reward_item_id' => $this->reward_item_id,
            'reward_item_name' => $this->whenLoaded('rewardItem', fn () => $this->rewardItem?->name),
            'reward_item_reward_type' => $this->whenLoaded('rewardItem', fn () => $this->rewardItem?->reward_type), // App\Enums\RewardType, TASK-042 §2
            'points_spent' => $this->points_spent,
            'status' => $this->status,
            'requested_at' => $this->requested_at,
            'decided_by' => $this->decided_by,
            'decided_by_name' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at,
            'decision_note' => $this->decision_note,
            // TASK-042 §2: captured by the agent at request time, required only
            // for physical items — see StoreRewardRedemptionRequest.
            'shipping_recipient_name' => $this->shipping_recipient_name,
            'shipping_phone' => $this->shipping_phone,
            'shipping_address' => $this->shipping_address,
            // Admin-editable any time after Approved — see
            // RewardRedemptionService::updateTrackingNumber().
            'tracking_number' => $this->tracking_number,
        ];
    }
}
