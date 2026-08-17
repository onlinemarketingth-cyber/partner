<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;

class StoreExamQuestionOptionRequest extends FormRequest
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
            'option_text' => ['required', 'string', 'max:500'],
            'is_correct' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
