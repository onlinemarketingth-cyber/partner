<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentRankResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'volume_threshold' => $this->volume_threshold,
            'sort_order' => $this->sort_order,
            'rate_type' => $this->rate_type?->value,
            'rate_value' => $this->rate_value,
            'is_breakaway_rank' => $this->is_breakaway_rank,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
