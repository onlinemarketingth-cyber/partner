<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModuleCompletionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'module_lesson' => new ModuleLessonResource($this->whenLoaded('moduleLesson')),
            'completed_at' => $this->completed_at,
            'score' => $this->score,
        ];
    }
}
