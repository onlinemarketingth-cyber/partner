<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Section 7: API Resources on every JSON response — these are real
// AuditLog model rows (unlike the curated-array report endpoints
// alongside this one in TASK-041, which return plain JsonResponse per
// ProductController::abcGrades()'s established pattern).
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'actor_name' => $this->whenLoaded('actor', fn () => $this->actor?->name),
            'action' => $this->action,
            // class_basename() shortens "App\Models\User" -> "User" for
            // display — the full class stays in auditable_type's raw DB
            // value if ever needed elsewhere, only this Resource's output
            // is shortened.
            'auditable_type' => class_basename($this->auditable_type),
            'auditable_id' => $this->auditable_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at,
        ];
    }
}
