<?php

namespace App\Http\Requests\Academy;

use App\Models\ModuleLesson;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * TASK-150 / ADR-030 §2.1 — link a library quiz to a lesson.
 *
 * Authorized as a lesson-authoring action (ModulePolicy::update on the
 * parent Section), the same gate as adding a question to that lesson: the
 * two are the same act from the admin's point of view, and letting them
 * diverge would mean someone could attach a quiz to a lesson they may not
 * edit.
 *
 * The exclusivity rule is checked in three places, on purpose and at
 * different strengths (ADR-030 §2.1):
 *
 *   1. HERE — so the admin gets a 422 with a Thai sentence.
 *   2. QuizService::attach() — inside a transaction with a locking re-read,
 *      so two simultaneous attaches serialise.
 *   3. The UNIQUE index on `module_lessons.quiz_id` — the actual rule. It
 *      holds even for a seeder, a console command, or a race that somehow
 *      slipped past 1 and 2.
 */
class AttachQuizToLessonRequest extends FormRequest
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
        /** @var ModuleLesson $lesson */
        $lesson = $this->route('moduleLesson');

        return [
            'quiz_id' => [
                'required',
                'integer',
                /*
                 * Scoped to the LESSON's company, not the actor's (BR-6):
                 * for a Super Admin the two differ, and a lesson may only
                 * ever hold a quiz from its own tenant (§2.5). Soft-deleted
                 * quizzes are excluded — a deleted quiz is not on offer.
                 */
                Rule::exists('quizzes', 'id')
                    ->where('company_id', $lesson->company_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * ADR-030 §2.1 — "a quiz that is linked to a lesson cannot be linked to
     * another".
     *
     * Counts SOFT-DELETED lessons as holders: such a lesson still occupies
     * the quiz_id as far as the UNIQUE index is concerned, so accepting the
     * attach here would produce a driver error two layers down.
     *
     * Re-attaching the quiz the lesson ALREADY holds is allowed and is a
     * no-op, so a double-submit does not turn into a false error.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var ModuleLesson $lesson */
            $lesson = $this->route('moduleLesson');

            $quizId = $this->input('quiz_id');

            if (! $quizId || $validator->errors()->has('quiz_id')) {
                return;
            }

            $holderId = DB::table('module_lessons')
                ->where('quiz_id', $quizId)
                ->value('id');

            if ($holderId !== null && (int) $holderId !== $lesson->id) {
                $validator->errors()->add('quiz_id', 'แบบทดสอบนี้ถูกใช้งานในบทเรียนอื่นแล้ว');
            }
        });
    }
}
