<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// TASK-015. Same {key,label} shape as ClientStatus/PipelineStage for
// UI consistency. can_edit lets the frontend show edit/delete controls
// only where the Policy would actually allow the action, without the
// UI needing to reimplement ClientActivityPolicy's rules itself.
class ClientActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'logged_by_user_id' => $this->logged_by_user_id,
            'logged_by_name' => $this->whenLoaded('loggedBy', fn () => trim("{$this->loggedBy->first_name} {$this->loggedBy->last_name}")),
            'type' => [
                'key' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'summary' => $this->summary,
            'occurred_at' => $this->occurred_at,
            'follow_up_at' => $this->follow_up_at,
            'can_edit' => $request->user()?->can('update', $this->resource) ?? false,
            'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
            'created_at' => $this->created_at,
        ];
    }
}
