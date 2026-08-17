<?php

namespace App\Services\Academy;

use App\Models\AuditLog;
use App\Models\ModuleLesson;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TASK-150 / ADR-030 — the quiz library, and the one place a quiz may be
 * linked to or unlinked from a lesson.
 *
 * The exclusivity rule ("one quiz belongs to at most one lesson, forever,
 * until it is explicitly unlinked" — §1) is enforced by the UNIQUE index on
 * `module_lessons.quiz_id`. Everything in this class exists so that an admin
 * meets a 422 with a sentence instead of a driver error (§2.1) — NOT as the
 * enforcement itself. If every check below were deleted the rule would still
 * hold; the database would just be ruder about it.
 */
class QuizService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Quiz
    {
        // Same shape as ModuleService::create — a Super Admin must name the
        // company, everyone else gets their own. Never read from the client
        // for a non-super-admin (BR-6/§5).
        $companyId = $actor->isSuperAdmin() ? ($data['company_id'] ?? null) : $actor->company_id;

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => 'company_id is required.']);
        }

        return Quiz::create([
            'company_id' => $companyId,
            'title' => $data['title'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Quiz $quiz, array $data): Quiz
    {
        // Only the title is updatable: company_id would be a tenant move
        // (BR-6) and the link lives on the lesson, not here.
        $quiz->update(['title' => $data['title']]);

        return $quiz;
    }

    /**
     * ADR-030 §2.4 — **A LINKED QUIZ CANNOT BE DELETED.**
     *
     * "Unlink first. Deleting under a lesson's feet would silently remove
     * its completion gate — and where `quiz_blocks_completion` is on, that
     * gate sits on the BR-1 certification path. Silently loosening a
     * certification requirement is not an acceptable failure mode for a
     * mis-click."
     *
     * Concretely, without this check: delete the quiz → the questions cascade
     * away → LessonCompletionGate::isQuizSatisfied() finds no questions and
     * returns true → a lesson that required a passing quiz is now completable
     * by pressing a button, with nothing in the UI to say so.
     */
    public function delete(Quiz $quiz): void
    {
        if ($quiz->isAttached()) {
            throw ValidationException::withMessages([
                'quiz_id' => 'แบบทดสอบนี้ถูกใช้งานอยู่ในบทเรียน กรุณายกเลิกการเชื่อมโยงก่อนจึงจะลบได้',
            ]);
        }

        // Soft delete (see the model): authored content is never destroyed
        // outright — ADR-030 §3.
        $quiz->delete();
    }

    /**
     * ADR-030 §2.5 — what the lesson's quiz picker may offer:
     * **unattached quizzes in the same company, plus the one currently
     * attached.**
     *
     * "A quiz already taken by another lesson must not appear as a choice
     * that then fails — the UI should not teach the rule by rejecting the
     * user (§9)."
     *
     * The company filter is the lesson's own company, not the actor's: for a
     * Super Admin those differ, and offering their own (or every) company's
     * quizzes would be a cross-tenant leak in a dropdown (BR-6).
     *
     * `whereNotExists` counts SOFT-DELETED lessons too — deliberately. Such a
     * lesson still occupies the quiz_id as far as the UNIQUE index is
     * concerned, so listing its quiz as free would produce exactly the
     * failing choice this method exists to prevent.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Quiz>
     */
    public function availableFor(ModuleLesson $lesson)
    {
        return Quiz::query()
            ->withoutGlobalScopes()
            ->where('company_id', $lesson->company_id)
            ->whereNull('deleted_at')
            ->where(function (Builder $query) use ($lesson) {
                $query->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('module_lessons')
                        ->whereColumn('module_lessons.quiz_id', 'quizzes.id');
                });

                if ($lesson->quiz_id !== null) {
                    // "...plus the one currently attached" (§2.5) — without
                    // this the picker could not show its own current value.
                    $query->orWhere('id', $lesson->quiz_id);
                }
            })
            ->orderBy('title')
            ->get();
    }

    /**
     * ADR-030 §2.1/§2.5 — attach a quiz to a lesson.
     *
     * Wrapped in a transaction with a `lockForUpdate` re-read, so two admins
     * pressing "attach" on the same quiz at the same moment serialise instead
     * of racing. Even if they did race, the UNIQUE index would reject the
     * loser — this only decides whether they see a sentence or a 500.
     */
    public function attach(ModuleLesson $lesson, Quiz $quiz, ?User $actor = null): ModuleLesson
    {
        return DB::transaction(function () use ($lesson, $quiz, $actor) {
            if ($quiz->company_id !== $lesson->company_id) {
                /*
                 * BR-6, defence in depth. The Form Request already scopes its
                 * `exists` rule to the lesson's company and TenantScope
                 * already narrows the route binding — but a Super Admin is
                 * exempt from TenantScope, and "the quiz and the lesson must
                 * belong to the SAME tenant" is a different question from
                 * "may this actor see both". Only this check asks it.
                 */
                throw ValidationException::withMessages([
                    'quiz_id' => 'แบบทดสอบนี้ไม่ได้อยู่ในบริษัทเดียวกับบทเรียน',
                ]);
            }

            $holder = ModuleLesson::withoutGlobalScopes()
                ->withTrashed()
                ->where('quiz_id', $quiz->id)
                ->lockForUpdate()
                ->first();

            if ($holder && $holder->id !== $lesson->id) {
                throw ValidationException::withMessages([
                    'quiz_id' => 'แบบทดสอบนี้ถูกใช้งานในบทเรียนอื่นแล้ว',
                ]);
            }

            $previousQuizId = $lesson->quiz_id;

            // Direct assignment, not fill(): quiz_id is deliberately absent
            // from ModuleLesson::$fillable so that this method and detach()
            // are the ONLY ways it can move (see the model's comment).
            $lesson->quiz_id = $quiz->id;
            $lesson->save();

            $this->audit($lesson, $actor, 'module_lesson.quiz_attached', $previousQuizId, $quiz->id);

            return $lesson;
        });
    }

    /**
     * ADR-030 §2.3 — "Unlinking returns the quiz to the library."
     *
     * **Attempts are not affected**, on purpose:
     * `module_lesson_quiz_attempts.module_lesson_id` keeps pointing at the
     * lesson, because an attempt is a record of *a learner doing a lesson*,
     * not of a quiz in the abstract. If the quiz is later attached to a
     * different lesson, the old attempts still correctly belong to the old
     * lesson, and `passed` was frozen at attempt time (ADR-029 §2.4).
     *
     * What DOES change, and is worth stating because it is a gate on the
     * BR-1 path: a detached lesson has no questions, so
     * LessonCompletionGate::isQuizSatisfied() short-circuits to true and the
     * lesson becomes completable again even with `quiz_blocks_completion`
     * still on. That is why this is audit-logged (CLAUDE.md §6 — every
     * action that affects status or certification).
     *
     * // TODO: CONFIRM (business rule) — ADR-030 §4 item 2 asks whether
     * // unlinking should WARN when learners have already attempted the
     * // lesson. "Recommended, not specified." Not implemented: a warning is
     * // a UI affordance and inventing its threshold here would be inventing
     * // a business rule.
     */
    public function detach(ModuleLesson $lesson, ?User $actor = null): ModuleLesson
    {
        $previousQuizId = $lesson->quiz_id;

        if ($previousQuizId === null) {
            return $lesson; // idempotent — nothing to unlink, nothing to log.
        }

        $lesson->quiz_id = null;
        $lesson->save();

        $this->audit($lesson, $actor, 'module_lesson.quiz_detached', $previousQuizId, null);

        return $lesson;
    }

    /**
     * ADR-030 §3 — "keeping 'create a new quiz right here' as the default
     * path; the library is an option, not a required detour."
     *
     * This is what lets the pre-existing authoring flow (POST
     * /module-lessons/{lesson}/quiz-questions) keep working unchanged: a
     * lesson that has no quiz gets one, named after itself, exactly as the
     * §2.2 data migration named the ones it created. The admin never has to
     * learn the library to type a question.
     */
    public function ensureForLesson(ModuleLesson $lesson, ?User $actor = null): Quiz
    {
        if ($lesson->quiz_id !== null) {
            // withoutGlobalScopes: the lesson was already authorized, and its
            // quiz is by definition in the lesson's company — while the ACTOR
            // may be a Super Admin whose TenantScope resolves elsewhere.
            $existing = Quiz::withoutGlobalScopes()->find($lesson->quiz_id);

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($lesson, $actor) {
            $quiz = Quiz::create([
                'company_id' => $lesson->company_id,
                'title' => $lesson->title,
            ]);

            $this->attach($lesson, $quiz, $actor);

            return $quiz;
        });
    }

    /**
     * CLAUDE.md §6 — attaching or detaching a quiz can switch a lesson's
     * completion gate on or off, and through `quiz_blocks_completion` that
     * gate feeds the BR-1 Basic certification that unlocks selling rights.
     * Who did it, when, and from-what → to-what is exactly the audit log's
     * remit.
     */
    private function audit(ModuleLesson $lesson, ?User $actor, string $action, ?int $oldQuizId, ?int $newQuizId): void
    {
        AuditLog::create([
            'company_id' => $lesson->company_id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => ModuleLesson::class,
            'auditable_id' => $lesson->id,
            'old_values' => ['quiz_id' => $oldQuizId],
            'new_values' => ['quiz_id' => $newQuizId],
            'ip_address' => request()?->ip(),
        ]);
    }
}
