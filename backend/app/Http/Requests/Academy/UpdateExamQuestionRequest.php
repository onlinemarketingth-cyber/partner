<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExamQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $examQuestion = $this->route('examQuestion');

        return $examQuestion !== null && $this->user()->can('update', $examQuestion->exam);
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
