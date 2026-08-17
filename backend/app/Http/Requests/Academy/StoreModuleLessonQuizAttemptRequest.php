<?php

namespace App\Http\Requests\Academy;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TASK-149 / ADR-029 §2.3 — the client submits `{question_id: option_id}`
 * and NOTHING ELSE.
 *
 * There is deliberately no `score`, no `passed`, no `total_questions` and
 * no `pass_percent` field here. Accepting any of them would let a learner
 * self-grade, and via `quiz_blocks_completion` (ADR-029 §2.6) a lesson
 * completion feeds the BR-1 Basic certification gate that unlocks selling
 * rights — the same reasoning StoreExamAttemptRequest states for exams
 * (CLAUDE.md §6: never trust the client).
 *
 * `user_id` is absent for the same reason it is absent from
 * StoreModuleCompletionRequest: ModuleLessonQuizAttemptService forces it to
 * the authenticated user.
 */
class StoreModuleLessonQuizAttemptRequest extends FormRequest
{
    /**
     * A learner may attempt the quiz of any lesson they are allowed to SEE.
     * ModulePolicy::view is the same check ModuleLessonController::stream()
     * and the progress endpoints make, so "can open the lesson", "can
     * record having opened it" and "can answer its quiz" cannot diverge.
     *
     * Cross-tenant is already 404 before this runs: the route-model-bound
     * lesson is TenantScope'd (§5 rule 5).
     *
     * Whether the quiz is UNLOCKED yet (ADR-029 §2.2) is a separate,
     * per-learner question answered in the Service, not here — it depends
     * on recorded progress rather than on authorization.
     */
    public function authorize(): bool
    {
        /** @var \App\Models\ModuleLesson $moduleLesson */
        $moduleLesson = $this->route('moduleLesson');

        return $this->user()->can('view', $moduleLesson->module);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\ModuleLesson $moduleLesson */
        $moduleLesson = $this->route('moduleLesson');

        // The LESSON's company, not the actor's: for a Super Admin the two
        // differ, and the answers must belong to the lesson being attempted.
        $companyId = $moduleLesson->company_id;

        return [
            // A JSON object body arrives as an associative array. `min:1`
            // is a sanity rule, not a business value — an empty submission
            // is a request with no content, and recording it as a 0-score
            // attempt would put noise in a table the Admin readout has to
            // be trustworthy about.
            'answers' => ['required', 'array', 'min:1'],
            // Existence + tenant scoping only. That the option belongs to
            // THIS question is a cross-table check the Service does once,
            // together with grading — the same division of labour
            // StoreExamAttemptRequest documents.
            'answers.*' => [
                'required', 'integer',
                Rule::exists('module_lesson_quiz_options', 'id')->where('company_id', $companyId),
            ],
        ];
    }

    /**
     * The array KEYS are question ids, and Laravel's rule syntax cannot
     * validate keys — so they are checked here rather than left to be
     * silently ignored by the grader. An unknown key means the client is
     * answering a question that is not on this lesson (a stale form, or a
     * probe), and answering it "successfully" would be a lie.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var \App\Models\ModuleLesson $moduleLesson */
            $moduleLesson = $this->route('moduleLesson');

            $answers = $this->input('answers');

            if (! is_array($answers)) {
                return;
            }

            // Tenant-safe by construction: the id list comes from THIS
            // lesson's own relation, so an id from another company (or
            // another lesson) can never match.
            $questionIds = $moduleLesson->quizQuestions()->pluck('id')->all();

            foreach (array_keys($answers) as $questionId) {
                if (! in_array((int) $questionId, $questionIds, true)) {
                    $validator->errors()->add('answers', 'คำตอบอ้างถึงคำถามที่ไม่ได้อยู่ในบทเรียนนี้');

                    return;
                }
            }
        });
    }
}
