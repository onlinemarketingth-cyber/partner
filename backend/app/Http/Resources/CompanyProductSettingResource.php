<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TASK-254 / ADR-040 — what one company decided about one shared product.
 *
 * `price_satang` is nullable and the null is the point: it means "no override,
 * this company pays the central price", which is a different statement from
 * any number. A screen that renders null as 0 would show a free product;
 * one that renders it as blank has to say WHY it is blank, which is why
 * `inherits_price` is sent alongside rather than left for the client to infer.
 */
class CompanyProductSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => (int) $this->company_id,
            'product_id' => (int) $this->product_id,
            // BR-3 — satang. Divided by 100 at the edge, by the screen.
            'price_satang' => $this->price_satang,
            'inherits_price' => $this->price_satang === null,
            'is_active' => (bool) $this->is_active,
            'updated_at' => $this->updated_at,
        ];
    }
}
