<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;

// Academy Sprint 1 — same authorization shape as StoreProductSpecRequest:
// creating a question is an "update" on the parent Exam (Company Admin
// own company / Super Admin only).
class StoreExamQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = $this->route('exam');

        return $exam !== null && $this->user()->can('update', $exam);
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
