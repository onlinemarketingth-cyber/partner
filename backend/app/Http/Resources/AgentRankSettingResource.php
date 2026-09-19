<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentRankSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'trailing_window_days' => $this->trailing_window_days,
            // resource()->volumeScope(), not $this->volume_scope: a row
            // written before the column existed reads back null, and the
            // admin screen must be told what the engine will actually do
            // (personal), not that nothing is set.
            'volume_scope' => $this->resource->volumeScope()->value,
            'recalculation_frequency' => $this->recalculation_frequency?->value,
            'last_recalculated_at' => $this->last_recalculated_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
