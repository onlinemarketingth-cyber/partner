<?php

namespace App\Http\Requests\Academy;

use App\Models\ModuleLessonQuizQuestion;
use Illuminate\Foundation\Http\FormRequest;

// Mirrors UpdateExamQuestionRequest exactly.
//
// ADR-030 §2.1 (TASK-150) — authorized through the QUIZ rather than the
// lesson's Module: a question can belong to a library quiz that no lesson
// holds, so `$question->moduleLesson->module` would be a null dereference on
// exactly the case ADR-030 exists to support. QuizPolicy::update grants the
// same actors ModulePolicy::update did, so nothing widens.
class UpdateModuleLessonQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ModuleLessonQuizQuestion|null $question */
        $question = $this->route('moduleLessonQuizQuestion');

        return $question && $this->user()->can('update', $question->quiz);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question_text' => ['sometimes', 'required', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
