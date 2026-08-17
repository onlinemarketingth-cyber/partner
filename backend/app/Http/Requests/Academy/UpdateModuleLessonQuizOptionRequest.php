<?php

namespace App\Http\Requests\Academy;

use App\Models\ModuleLessonQuizOption;
use Illuminate\Foundation\Http\FormRequest;

// Mirrors UpdateExamQuestionOptionRequest exactly.
//
// ADR-030 §2.1 (TASK-150) — the chain now ends at the QUIZ, not at a
// lesson's Module: `...->moduleLesson->module` would be a null dereference
// for an option inside a library quiz no lesson has taken yet.
class UpdateModuleLessonQuizOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ModuleLessonQuizOption|null $option */
        $option = $this->route('moduleLessonQuizOption');

        return $option && $this->user()->can('update', $option->moduleLessonQuizQuestion->quiz);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'option_text' => ['sometimes', 'required', 'string', 'max:500'],
            'is_correct' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
