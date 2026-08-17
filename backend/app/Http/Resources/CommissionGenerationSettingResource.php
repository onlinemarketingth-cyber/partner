<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionGenerationSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'max_generation_depth' => $this->max_generation_depth,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
