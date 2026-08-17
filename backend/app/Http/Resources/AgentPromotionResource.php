<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentPromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product?->name),
            'name' => $this->name,
            'description' => $this->description,
            'target_type' => $this->target_type,
            'target_cert_tier_id' => $this->target_cert_tier_id,
            'target_cert_tier_mode' => $this->target_cert_tier_mode,
            'target_cert_tier_name' => $this->whenLoaded('targetCertTier', fn () => $this->targetCertTier?->name),
            'target_agent_ids' => $this->whenLoaded('targetAgents', fn () => $this->targetAgents->pluck('id')),
            'bonus_type' => $this->bonus_type,
            'bonus_value' => $this->bonus_value,
            'payout_timing' => $this->payout_timing,
            'status' => $this->status,
            'is_currently_active' => $this->isCurrentlyActive(),
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
