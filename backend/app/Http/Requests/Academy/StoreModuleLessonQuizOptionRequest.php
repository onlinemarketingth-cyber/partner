<?php

namespace App\Http\Requests\Academy;

use App\Models\ModuleLessonQuizQuestion;
use Illuminate\Foundation\Http\FormRequest;

// Mirrors StoreExamQuestionOptionRequest exactly.
//
// ADR-030 §2.1 (TASK-150) — authorized through the question's QUIZ: the
// question may belong to a library quiz with no lesson, so there is not
// always a Module to ask. QuizPolicy::update grants the same actors
// ModulePolicy::update did.
class StoreModuleLessonQuizOptionRequest extends FormRequest
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
            'option_text' => ['required', 'string', 'max:500'],
            'is_correct' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
