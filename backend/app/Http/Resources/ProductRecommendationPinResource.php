<?php

namespace App\Http\Resources;

use App\Services\Catalog\ProductPricingService;
use App\Support\RequestScopedService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductRecommendationPinResource extends JsonResource
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
            // TASK-257 / ADR-040 — a pin belongs to a company, and so does the
            // price it advertises. Reading the row would show the CENTRAL
            // price for a shared product on that company's storefront.
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                // Request-scoped: pins are always rendered as a list.
                'price_satang' => RequestScopedService::get($request, ProductPricingService::class)
                    ->effectivePriceSatang($this->product, (int) $this->company_id),
            ]),
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
