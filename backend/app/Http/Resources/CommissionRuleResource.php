<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'cert_tier' => $this->whenLoaded('certTier', fn () => [
                'id' => $this->certTier->id,
                'key' => $this->certTier->key,
                'name' => $this->certTier->name,
            ]),
            'product' => new ProductResource($this->whenLoaded('product')),
            // ADR-011/TASK-028 — null unless this row is a category-wide
            // rule; both product and product_category null = company-wide
            // default. See CommissionService::resolveCommissionRule().
            'product_category' => new ProductCategoryResource($this->whenLoaded('productCategory')),
            'rate_type' => $this->rate_type?->value,
            'rate_value' => $this->rate_value,
            'effective_from' => $this->effective_from,
            'effective_to' => $this->effective_to,
            // TASK-024 — null/null/false unless an admin has configured a
            // renewal rate for this rule.
            'renewal_rate_type' => $this->renewal_rate_type?->value,
            'renewal_rate_value' => $this->renewal_rate_value,
            'renewal_recurs' => $this->renewal_recurs,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
