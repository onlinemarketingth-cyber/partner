<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSpecResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'spec_group' => $this->spec_group,
            'spec_key' => $this->spec_key,
            'spec_value' => $this->spec_value,
            'sort_order' => $this->sort_order,
        ];
    }
}
