<?php

namespace App\Http\Requests\Academy;

use App\Models\ModuleLesson;
use Illuminate\Foundation\Http\FormRequest;

// Mirrors StoreExamQuestionRequest exactly.
class StoreModuleLessonQuizQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ModuleLesson|null $lesson */
        $lesson = $this->route('moduleLesson');

        return $lesson && $this->user()->can('update', $lesson->module);
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
