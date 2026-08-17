<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPricePromotionResource extends JsonResource
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
            'product_price_satang' => $this->whenLoaded('product', fn () => $this->product?->price_satang),
            'discounted_price_satang' => $this->discounted_price_satang,
            'note' => $this->note,
            'status' => $this->status,
            'is_currently_active' => $this->isCurrentlyActive(),
            'starts_at' => $this->starts_at?->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
