<?php

namespace App\Http\Requests\Academy;

use App\Models\Quiz;
use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-150 / ADR-030 — rename a library quiz.
 *
 * `company_id` is absent on purpose: moving a quiz between tenants is not a
 * rename, it is a BR-6 violation with a friendly label. The link to a lesson
 * is absent for a different reason — it lives on `module_lessons.quiz_id`
 * and may only move through the attach/detach endpoints (§2.1).
 */
class UpdateQuizRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
