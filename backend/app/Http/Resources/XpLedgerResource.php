<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class XpLedgerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'source_type' => $this->source_type?->value,
            'source_id' => $this->source_id,
            'xp_awarded' => $this->xp_awarded,
            'created_at' => $this->created_at,
        ];
    }
}
