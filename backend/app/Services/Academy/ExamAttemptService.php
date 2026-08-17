<?php

namespace App\Services\Academy;

use App\Enums\GamificationSourceType;
use App\Enums\NotificationType;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Gamification\GamificationService;
use App\Services\Notification\NotificationService;
use Illuminate\Validation\ValidationException;

// BR-1: "An agent must pass the Basic certification before gaining
// access to SWS Referral submission and selling features." This
// Service is where that gate actually gets unlocked — `passed` is
// always computed here (score >= exam.passing_score), never trusted
// from the client, and a passing attempt creates the one
// user_certifications row that every future Policy checks via
// User::hasPassedCertTier().
//
// Academy Sprint 1 (human-requested 2026-07-21): score is no longer
// accepted from the client at all — grading happens entirely here,
// server-side, against exam_question_options.is_correct.
class ExamAttemptService
{
    public function __construct(
        private GamificationService $gamificationService,
        private NotificationService $notifier,
    ) {}

    /**
     * @param  array<int, array{question_id: int, option_id: int}>  $answers
     */
    public function attempt(Exam $exam, User $agent, array $answers): ExamAttempt
    {
        if ($exam->company_id !== $agent->company_id) {
            throw ValidationException::withMessages(['exam_id' => 'This exam does not belong to your company.']);
        }

        $questions = $exam->questions()->with('options')->get();

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages(['exam_id' => 'This exam has no questions yet.']);
        }

        // question_id => option_id the agent selected. If the client
        // somehow sends two answers for the same question, the last one
        // wins — no meaningful way to "average" two picks for one
        // question, and StoreExamAttemptRequest doesn't forbid it.
        $selectedOptionIdByQuestion = collect($answers)->pluck('option_id', 'question_id');

        $correctCount = 0;
        foreach ($questions as $question) {
            $selectedOptionId = $selectedOptionIdByQuestion->get($question->id);
            if ($selectedOptionId === null) {
                continue; // unanswered → counts as incorrect
            }

            // Defensive: StoreExamAttemptRequest already confirmed this
            // option_id belongs to SOME question in the agent's company,
            // but not that it belongs to THIS question — only count it if
            // it's genuinely one of this question's own options (guards
            // against an agent submitting a valid-but-mismatched option
            // id lifted from a different question/exam).
            $selectedOption = $question->options->firstWhere('id', (int) $selectedOptionId);
            if ($selectedOption && $selectedOption->is_correct) {
                $correctCount++;
            }
        }

        $score = (int) round(($correctCount / $questions->count()) * 100);
        $passed = $score >= $exam->passing_score;

        $attempt = ExamAttempt::create([
            'company_id' => $agent->company_id,
            'user_id' => $agent->id,
            'exam_id' => $exam->id,
            'score' => $score,
            'passed' => $passed,
            'attempted_at' => now(),
        ]);

        if ($passed) {
            // firstOrCreate: passing the same tier's exam twice (e.g. a
            // retake after an admin edits the exam) must not create a
            // second certification row or reset an already-earned one
            // (BR-1's gate is "has passed", not "passed most recently").
            $certification = UserCertification::firstOrCreate(
                ['user_id' => $agent->id, 'cert_tier_id' => $exam->cert_tier_id],
                ['company_id' => $agent->company_id, 'passed_at' => now(), 'exam_attempt_id' => $attempt->id],
            );

            // BR-5 (XP source (a): "passing certification exams") — but
            // unlike ModuleCompletion, ExamAttempt itself has NO
            // uniqueness constraint (retaking an exam repeatedly is
            // explicitly allowed, even after already passing — see
            // test_retaking_a_passed_exam_does_not_duplicate_the_certification).
            // Awarding XP off every passing ExamAttempt would be
            // farmable (retake + re-pass the same exam indefinitely for
            // free XP). Gating on the CERTIFICATION's wasRecentlyCreated
            // instead — which IS deduplicated per (user, cert_tier) —
            // means XP is only ever awarded the genuine first time this
            // agent reaches this tier, reusing BR-1's own idempotency
            // rather than adding a second, separate dedup mechanism.
            if ($certification->wasRecentlyCreated) {
                $this->gamificationService->awardXp($agent, GamificationSourceType::ExamPassed, $attempt->id);
            }
        }

        // TASK-053 Phase 2b — tell the agent the result on their home
        // bell. Fired on every attempt (pass OR fail) so a failed retake
        // is surfaced too; unlike XP above it's not farmable — a
        // notification is informational, carrying no reward.
        if ($passed) {
            $this->notifier->notify(
                $agent,
                NotificationType::ExamPassed,
                "สอบผ่าน: {$exam->title}",
                "คุณทำคะแนนได้ {$score}%",
                '/academy',
                ['exam_id' => $exam->id, 'attempt_id' => $attempt->id, 'score' => $score],
            );
        } else {
            $this->notifier->notify(
                $agent,
                NotificationType::ExamFailed,
                "ยังไม่ผ่าน: {$exam->title}",
                "คะแนน {$score}% (เกณฑ์ผ่าน {$exam->passing_score}%) — ลองอีกครั้งได้",
                '/academy',
                ['exam_id' => $exam->id, 'attempt_id' => $attempt->id, 'score' => $score],
            );
        }

        return $attempt;
    }
}
