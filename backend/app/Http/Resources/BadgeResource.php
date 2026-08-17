<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BadgeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id, // null = platform-wide default
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            // Phase 10: read by BadgeConditionEvaluator/BadgeAutoAwardService
            // when non-null (ERD-001 open question #9). Null = manual-award-only.
            'condition_config' => $this->condition_config,
            'created_at' => $this->created_at,
        ];
    }
}
