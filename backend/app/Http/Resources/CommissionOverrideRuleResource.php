<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionOverrideRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            // TASK-214 — the scope pair, same shape CommissionRuleResource
            // uses, so the Admin UI can render one list of both kinds of
            // rate with one label function.
            'product' => $this->whenLoaded('product', fn () => $this->product === null ? null : [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            'product_category' => $this->whenLoaded('productCategory', fn () => $this->productCategory === null ? null : [
                'id' => $this->productCategory->id,
                'name' => $this->productCategory->name,
            ]),
            // Legacy annotation only — no longer read when resolving a
            // payout (TASK-214). Kept so the collapse command and the
            // Admin UI can still explain what a pre-TASK-214 row meant.
            'manager_cert_tier' => $this->whenLoaded('managerCertTier', fn () => $this->managerCertTier === null ? null : [
                'id' => $this->managerCertTier->id,
                'key' => $this->managerCertTier->key,
                'name' => $this->managerCertTier->name,
            ]),
            'rate_type' => $this->rate_type?->value,
            'rate_value' => $this->rate_value,
            'effective_from' => $this->effective_from,
            'effective_to' => $this->effective_to,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
