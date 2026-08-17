<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LevelThresholdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level_number' => $this->level_number,
            'xp_required' => $this->xp_required,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
