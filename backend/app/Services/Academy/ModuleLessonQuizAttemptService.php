<?php

namespace App\Services\Academy;

use App\Models\ModuleLesson;
use App\Models\ModuleLessonQuizAttempt;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * TASK-149 / ADR-029 §2.3 — grading for the end-of-lesson quiz.
 *
 * "New `module_lesson_quiz_attempts`. The client submits
 * `{question_id: option_id}` only; the server decides what is correct.
 * `ExamAttemptService` is the existing, working precedent — follow it
 * rather than inventing a second grading style." So this class is
 * deliberately ExamAttemptService's shape: same defensive
 * option-belongs-to-this-question check, same "unanswered counts as
 * incorrect", same "score/passed are computed here and never accepted from
 * the client" (CLAUDE.md §6 — trusting a client-sent score is how anyone
 * self-certifies).
 *
 * THREE THINGS IT DELIBERATELY DOES NOT DO:
 *
 * 1. **No XP.** ADR-029 §4 item 1 ("whether XP should be awarded for
 *    passing a quiz") is UNRESOLVED. XP feeds levels, badges, the
 *    leaderboard and the promotion bonuses that pay real money (TASK-042),
 *    and §2.5 allows unlimited retries — an XP award here would be
 *    farmable by definition. Not implemented; ask before adding.
 *
 * 2. **No attempt cap and no cooldown** (ADR-029 §2.5). Every attempt is
 *    recorded so an admin can see someone who took eleven tries.
 *
 * 3. **No storing of the chosen answers.** ADR-029 §4 item 2 is unresolved
 *    and PDPA-adjacent: score only until asked.
 *
 * ADR-029 §2.7 is worth restating, because someone will notice it and
 * think it is a bug: unlimited retries plus per-question feedback means a
 * determined agent can converge on the answers by elimination. That is
 * ACCEPTED. This quiz exists so the material is understood. **The gate
 * that must resist gaming is the certification exam at the cert-tier level
 * (BR-1) — a different system, with a real pass score.** Do not let anyone
 * describe this quiz as exam security.
 */
class ModuleLessonQuizAttemptService
{
    public function __construct(
        private AcademyCompletionSettingService $settings,
        private LessonCompletionGate $gate,
        // TASK-151 / ADR-031 §2.2 — see the lock check in attempt().
        private LessonAccessGate $access,
        // TASK-165 §3.3 — see the tail of attempt().
        private ModuleCompletionService $completions,
    ) {}

    /**
     * ADR-029 §2.4 — most-specific-wins, the same resolution shape as
     * commission rule scoping (TASK-028) and the pipeline template chain
     * (CLAUDE.md §4.3):
     *
     *     module_lessons.quiz_pass_percent
     *       ?? academy_completion_settings.quiz_pass_percent (default 80)
     *
     * BR-7 — the number is never a constant in this file. This is the ONE
     * place it is resolved, so the two levels can never drift apart.
     */
    public function passPercentFor(ModuleLesson $lesson): int
    {
        return $lesson->quiz_pass_percent
            ?? $this->settings->forCompany($lesson->company_id)['quiz_pass_percent'];
    }

    /**
     * Grade a submission and record the attempt.
     *
     * @param  array<int|string, int|string>  $answers  question_id => option_id, validated by
     *                                                  StoreModuleLessonQuizAttemptRequest
     * @return array{attempt: ModuleLessonQuizAttempt, results: array<int, array{question_id: int, answered: bool, is_correct: bool}>}
     */
    public function attempt(ModuleLesson $lesson, User $agent, array $answers): array
    {
        if ($lesson->company_id !== $agent->company_id) {
            // BR-6 defense-in-depth beyond the Form Request's tenant-scoped
            // `exists` rules and the route's TenantScope, mirroring
            // ExamAttemptService and ModuleCompletionService.
            throw ValidationException::withMessages([
                'module_lesson_id' => 'This lesson does not belong to your company.',
            ]);
        }

        /*
         * TASK-151 / ADR-031 §2.2 — a LOCKED lesson's quiz cannot be
         * attempted.
         *
         * Not named in ADR-031, which lists stream + completion + progress,
         * but it is the same hole and it matters most exactly where the
         * other three do not bite: a `link` lesson, or one with
         * `is_downloadable = true`, satisfies isContentEarned() trivially
         * (ADR-028 §2.3), so the §2.2 check below would let a learner sit —
         * and PASS — the quiz of a lesson they may not open. Where
         * `quiz_blocks_completion` is on (ADR-029 §2.6) that is a recorded
         * step on the BR-1 certification path, earned out of order.
         *
         * Checked BEFORE the questions are even loaded: a locked learner
         * should not learn how many questions there are either.
         */
        $lockReason = $this->access->reasonFor($lesson, $agent);

        if ($lockReason !== null) {
            throw ValidationException::withMessages([
                'module_lesson_id' => $lockReason->message(),
            ]);
        }

        $questions = $lesson->quizQuestions()->with('options')->get();

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'module_lesson_id' => 'บทเรียนนี้ยังไม่มีแบบทดสอบ',
            ]);
        }

        /*
         * ADR-029 §2.2 — THE QUIZ IS LOCKED UNTIL THE CONTENT GATE IS MET.
         * "Answering questions about a video you have not watched is not a
         * comprehension check."
         *
         * Enforced here, server-side, and not merely signalled by
         * ModuleLessonResource's `quiz_unlocked` flag: a flag the client is
         * free to ignore is exactly the client-trusting shape ADR-028 §3
         * rejected. Note this reads the CONTENT gate specifically
         * (isContentEarned), not isEarned — isEarned now also asks whether
         * the quiz was passed, which would be circular here.
         *
         * The message is actionable but non-specific, consistent with
         * ADR-028 §4: it says what to do, never how far they got.
         */
        if (! $this->gate->isContentEarned($lesson, $agent)) {
            throw ValidationException::withMessages([
                'module_lesson_id' => 'กรุณาเรียนเนื้อหาให้ครบก่อนจึงจะทำแบบทดสอบได้',
            ]);
        }

        // question_id => option_id. Keys arrive as strings from a JSON
        // object body, so both sides of every lookup are cast explicitly.
        $selectedOptionIdByQuestion = collect($answers)
            ->mapWithKeys(fn ($optionId, $questionId) => [(int) $questionId => (int) $optionId]);

        $correctCount = 0;
        $results = [];

        foreach ($questions as $question) {
            $selectedOptionId = $selectedOptionIdByQuestion->get($question->id);

            if ($selectedOptionId === null) {
                // Unanswered → counts as incorrect, exactly as
                // ExamAttemptService treats an omitted answer.
                $results[] = ['question_id' => $question->id, 'answered' => false, 'is_correct' => false];

                continue;
            }

            /*
             * Defensive, copied verbatim in spirit from ExamAttemptService:
             * the Form Request already confirmed this option_id belongs to
             * SOME question in the lesson's company, but not that it
             * belongs to THIS question. Only count it if it is genuinely
             * one of this question's own options — otherwise an agent could
             * submit a valid-but-mismatched option id lifted from another
             * question.
             */
            $selectedOption = $question->options->firstWhere('id', $selectedOptionId);
            $isCorrect = (bool) ($selectedOption?->is_correct);

            if ($isCorrect) {
                $correctCount++;
            }

            $results[] = ['question_id' => $question->id, 'answered' => true, 'is_correct' => $isCorrect];
        }

        $totalQuestions = $questions->count();
        $passPercent = $this->passPercentFor($lesson);

        /*
         * Integer cross-multiplication rather than
         * `round($correct / $total * 100) >= $percent`.
         *
         * Same server-side grading precedent as ExamAttemptService — the
         * DIFFERENCE is only in how the comparison is expressed, and it is
         * deliberate: rounding a percentage can round a near-miss UP across
         * the threshold (79.6% becoming a pass at 80%), which is a silent
         * weakening of a gate that feeds BR-1 through
         * quiz_blocks_completion. There is no float anywhere in this line.
         */
        $passed = ($correctCount * 100) >= ($passPercent * $totalQuestions);

        $attempt = ModuleLessonQuizAttempt::create([
            // §5/BR-6 — resolved from the LESSON on the server, never from
            // the client, and never guessed from the actor.
            'company_id' => $lesson->company_id,
            'user_id' => $agent->id,
            'module_lesson_id' => $lesson->id,
            // score = COUNT of correct answers (see the migration docblock
            // for why this is a count and not a percent).
            'score' => $correctCount,
            'total_questions' => $totalQuestions,
            // Frozen at attempt time: raising the pass mark later must not
            // retroactively un-pass someone, same reasoning as ADR-029 §3's
            // guarantee about module_completions.
            'passed' => $passed,
            'attempted_at' => now(),
        ]);

        // Note what is NOT here: no GamificationService::awardXp() call FOR
        // THE ATTEMPT. See this class's docblock, point 1 — ADR-029 §4
        // item 1. (completeIfEarned() below may award the LESSON's XP, once,
        // through BR-5 source (a) — a different award for a different
        // event, and it is farm-proof because a lesson completes once.)

        /*
         * TASK-165 §3.3 — THE CASE THAT GETS MISSED.
         *
         * A lesson with `quiz_blocks_completion` is not earned by reading
         * alone. The learner reads to 100% — no completion fires, correctly,
         * because isQuizSatisfied() is false — then passes the quiz here,
         * and **no further progress ping ever arrives**: there is nothing
         * left to read. Without this call that learner sits at "not
         * complete" forever having done everything that was asked of them.
         *
         * So the same check runs on this path too, after the attempt is
         * graded and stored (the gate reads `passed` from the stored row —
         * ADR-029 §2.6 — so it must exist first).
         *
         * It is the SAME method as the progress path, which is what keeps
         * the two honest: an unmeasurable lesson (§2, the button side) is
         * refused here as well, so passing a quiz never auto-completes a
         * lesson whose completion control the learner can still see.
         */
        $this->completions->completeIfEarned($lesson, $agent);

        return ['attempt' => $attempt, 'results' => $results];
    }
}
