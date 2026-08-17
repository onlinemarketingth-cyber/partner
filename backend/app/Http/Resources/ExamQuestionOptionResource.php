<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Only ever returned from the authoring CRUD endpoints (store/update),
// which are already gated to Company Admin/Super Admin by
// StoreExamQuestionOptionRequest/UpdateExamQuestionOptionRequest — safe
// to always include is_correct here. Contrast with ExamResource's
// embedded `questions.options`, which is reachable by the Agent role too
// and must mask is_correct there instead.
class ExamQuestionOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_question_id' => $this->exam_question_id,
            'option_text' => $this->option_text,
            'is_correct' => $this->is_correct,
            'sort_order' => $this->sort_order,
        ];
    }
}
