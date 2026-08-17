<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Used only by the admin-gated authoring CRUD endpoints (mirrors
// ExamQuestionOptionResource) — always includes is_correct
// unconditionally, since those endpoints already require the `update`
// ability on the parent Module. Agents taking the quiz see the masked
// version embedded in ModuleLessonResource instead.
class ModuleLessonQuizOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module_lesson_quiz_question_id' => $this->module_lesson_quiz_question_id,
            'option_text' => $this->option_text,
            'is_correct' => $this->is_correct,
            'sort_order' => $this->sort_order,
        ];
    }
}
