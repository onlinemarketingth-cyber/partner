<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExamQuestionOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $option = $this->route('examQuestionOption');

        return $option !== null && $this->user()->can('update', $option->examQuestion->exam);
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
