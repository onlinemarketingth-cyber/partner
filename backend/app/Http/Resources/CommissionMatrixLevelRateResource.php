<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionMatrixLevelRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'level' => $this->level,
            'rate_type' => $this->rate_type?->value,
            'rate_value' => $this->rate_value,
            'effective_from' => $this->effective_from,
            'effective_to' => $this->effective_to,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
