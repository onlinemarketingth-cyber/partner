<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Mirrors ExamQuestionResource — admin-gated authoring CRUD only.
//
// ADR-030 §2.1 (TASK-150) — `module_lesson_id` is replaced by `quiz_id`: a
// question belongs to a quiz, and that quiz may not be attached to any
// lesson at all. Returning the old field would mean either a lie (null on a
// perfectly valid library question) or an extra join to guess a lesson the
// question does not actually belong to.
class ModuleLessonQuizQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quiz_id' => $this->quiz_id,
            'question_text' => $this->question_text,
            'sort_order' => $this->sort_order,
            'options' => ModuleLessonQuizOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
