<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-036 — mirrors ProductSpecResource's shape exactly, plus catalog_item_id.
class ProductCatalogSpecResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'catalog_item_id' => $this->catalog_item_id,
            'spec_group' => $this->spec_group,
            'spec_key' => $this->spec_key,
            'spec_value' => $this->spec_value,
            'sort_order' => $this->sort_order,
        ];
    }
}
