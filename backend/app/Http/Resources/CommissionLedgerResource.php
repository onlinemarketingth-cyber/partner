<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionLedgerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'referral' => $this->whenLoaded('referral', fn () => [
                'id' => $this->referral->id,
                'client' => $this->referral->relationLoaded('client') ? [
                    'id' => $this->referral->client?->id,
                    'name' => $this->referral->client?->name,
                ] : null,
            ]),
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent->id,
                'name' => $this->agent->name,
            ]),
            'cert_tier_at_time' => $this->whenLoaded('certTierAtTime', fn () => [
                'id' => $this->certTierAtTime->id,
                'key' => $this->certTierAtTime->key,
                'name' => $this->certTierAtTime->name,
            ]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            'rate_type_applied' => $this->rate_type_applied?->value,
            'rate_applied' => $this->rate_applied,
            // BR-3 — satang stays an integer all the way to the wire;
            // only the UI display layer divides by 100.
            'amount_satang' => $this->amount_satang,
            // TASK-047 — immutable snapshot of the price basis this row was
            // actually calculated against (post-promotion if one was
            // active at Complete Payment time). Null for row types this
            // task didn't extend (BinaryMatch/MatrixOverride/
            // StairstepOverride/GenerationOverride/PromotionBonus) — the
            // UI must render "—", never fabricate a value.
            'sale_price_satang_at_time' => $this->sale_price_satang_at_time,
            'applied_price_promotion' => $this->whenLoaded('appliedPricePromotionAtTime', fn () => $this->appliedPricePromotionAtTime ? [
                'id' => $this->appliedPricePromotionAtTime->id,
                'note' => $this->appliedPricePromotionAtTime->note,
                'discounted_price_satang' => $this->appliedPricePromotionAtTime->discounted_price_satang,
            ] : null),
            'payment_status' => $this->payment_status?->value,
            // TASK-046 — "how was this calculated" drill-down. earned_via
            // is the single most important field for that question (BR-4:
            // distinguishes a direct sale from a renewal/override/binary
            // match/etc — see CommissionEarnedVia's own docblock) and was
            // already stored on every row, just never exposed here before.
            'earned_via' => $this->earned_via?->value,
            'override_source_agent' => $this->whenLoaded('overrideSourceAgent', fn () => $this->overrideSourceAgent ? [
                'id' => $this->overrideSourceAgent->id,
                'name' => $this->overrideSourceAgent->name,
            ] : null),
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}
