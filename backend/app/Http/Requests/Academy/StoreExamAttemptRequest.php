<?php

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Academy Sprint 1 — replaces the old placeholder shape (client sent a
// raw self-reported `score`). Score/passed are NEVER accepted from the
// client — ExamAttemptService grades `answers` server-side against
// exam_question_options.is_correct and computes `passed` by comparing
// against exams.passing_score, since trusting any client-sent score or
// "passed": true would let anyone self-certify (breaks BR-1).
class StoreExamAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\ExamAttempt::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'exam_id' => ['required', 'integer', Rule::exists('exams', 'id')->where('company_id', $companyId)],
            // One answer per question the agent chose to answer — an
            // unanswered question is simply omitted (ExamAttemptService
            // scores it as incorrect, see that Service's own comment).
            // Deliberately no `required` count check here: which exam_id
            // each question_id belongs to is a cross-table check the
            // Service is better placed to do once, together with grading,
            // rather than duplicating the join in a Rule::exists closure.
            'answers' => ['sometimes', 'array'],
            'answers.*.question_id' => ['required_with:answers', 'integer', Rule::exists('exam_questions', 'id')->where('company_id', $companyId)],
            'answers.*.option_id' => ['required_with:answers', 'integer', Rule::exists('exam_question_options', 'id')->where('company_id', $companyId)],
        ];
    }
}
