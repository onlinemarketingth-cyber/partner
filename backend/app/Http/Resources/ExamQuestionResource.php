<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Only ever returned from the authoring CRUD endpoints (store/update),
// already gated to Company Admin/Super Admin — safe to always include
// options with is_correct here (see ExamQuestionOptionResource's own
// comment for the contrast with the Agent-reachable ExamResource path).
class ExamQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'question_text' => $this->question_text,
            'sort_order' => $this->sort_order,
            'options' => ExamQuestionOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
