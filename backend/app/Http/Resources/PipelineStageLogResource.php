<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Section 4.3's audit trail, rendered read-only. from_stage is null
// only for the very first log row (referral creation).
class PipelineStageLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_stage' => $this->from_stage ? [
                'key' => $this->from_stage->value,
                'label' => $this->from_stage->label(),
            ] : null,
            'to_stage' => [
                'key' => $this->to_stage->value,
                'label' => $this->to_stage->label(),
            ],
            'changed_by' => $this->whenLoaded('changedBy', fn () => [
                'id' => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ]),
            'changed_at' => $this->changed_at,
        ];
    }
}
