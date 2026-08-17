<?php

namespace App\Http\Requests\Academy;

use App\Models\Quiz;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-150 / ADR-030 §2.1 — author a question straight into a LIBRARY quiz,
 * which may not be attached to any lesson.
 *
 * Identical rules to StoreModuleLessonQuizQuestionRequest (the lesson-scoped
 * twin); only the authorization subject differs, and it has to: there may be
 * no lesson and therefore no Module to ask ModulePolicy about. QuizPolicy
 * grants the same actors, so nothing widens.
 */
class StoreQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Quiz|null $quiz */
        $quiz = $this->route('quiz');

        return $quiz && $this->user()->can('update', $quiz);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question_text' => ['required', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
