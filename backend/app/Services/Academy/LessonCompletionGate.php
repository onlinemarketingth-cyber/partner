<?php

namespace App\Services\Academy;

use App\Enums\MediaSourceType;
use App\Enums\ModuleContentType;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonProgress;
use App\Models\ModuleLessonQuizAttempt;
use App\Models\ModuleLessonQuizQuestion;
use App\Models\User;

/**
 * TASK-146 / ADR-028 §2.3 — decides whether a learner has EARNED a lesson
 * completion, from the progress the server recorded.
 *
 * Why this matters beyond the Academy: ModuleCompletionService used to
 * write a completion row on any POST, so an agent could "finish" a
 * 40-minute video without opening it, then sit the exam and pass the Basic
 * gate BR-1 uses to unlock selling rights. The certification is only as
 * meaningful as the learning behind it (ADR-028 §1).
 *
 * Reads `max_*`, NEVER `last_*` (ADR-028 §2.3).
 *
 * TASK-149 / ADR-029 §2.6 — the gate now has TWO independent halves:
 *
 *     isEarned = isContentEarned  AND  isQuizSatisfied
 *
 * They are kept as separate public methods rather than one flattened
 * condition because the CONTENT half is also the thing that decides whether
 * the quiz is even unlocked (ADR-029 §2.2). Folding them together would
 * make that question unaskable — and asking `isEarned` for it would be
 * circular, since isEarned now depends on the quiz.
 */
class LessonCompletionGate
{
    public function __construct(private AcademyCompletionSettingService $settings) {}

    /**
     * ADR-028 §2.3 + ADR-029 §2.6 — the full completion gate.
     */
    public function isEarned(ModuleLesson $lesson, User $agent): bool
    {
        return $this->isContentEarned($lesson, $agent)
            && $this->isQuizSatisfied($lesson, $agent);
    }

    /**
     * TASK-165 / ADR-028 §2 — CAN THE SERVER MEASURE THIS LESSON AT ALL?
     *
     * "Where the system can measure, it records. Where it cannot, the
     * learner still tells us." This method is the "can measure" half, and
     * it is deliberately the ONLY implementation of it:
     *
     *  - `isContentEarned()` below is written on top of it, so the gate
     *    cannot disagree with itself;
     *  - `ModuleLessonResource::completion_is_automatic` exposes THIS
     *    answer to the client, so the Vue side never re-derives
     *    "verifiable" from content_type + source_type. A duplicated
     *    predicate in Vue is the most repeated defect in this codebase
     *    (TASK-159's `from`/`to` vs `color1`/`color2` gradient mismatch),
     *    and here it would decide whether a learner is shown a completion
     *    control on the BR-1 path.
     *
     * A FALSE answer is not a failure — it is the ADR-028 §2.3 fallback:
     * the learner presses the button and we take their word for it.
     *
     * NOTE this asks nothing about the LEARNER. It is a property of the
     * lesson (what we can observe about it), which is why the resource can
     * expose it without a per-row query.
     */
    public function isMeasurable(ModuleLesson $lesson): bool
    {
        // ADR-028 §2.3, stated openly rather than pretended away: a
        // downloadable file can be read outside the app, so a gate over
        // in-app position would measure nothing. Completion falls back to
        // the button.
        if ($lesson->is_downloadable) {
            return false;
        }

        // Only OUR OWN uploaded media can be tracked. An external URL or
        // an iframe embed is somebody else's page: we get no position
        // events from it, and blocking completion on evidence we can never
        // receive would lock the learner out permanently.
        if ($lesson->source_type !== MediaSourceType::Upload) {
            return false;
        }

        if (! $lesson->content_type?->hasPositionalProgress()) {
            // image / link / quiz — ADR-028 §2.3 gives them no positional
            // tracking, so the button remains the trigger. (A quiz lesson
            // is still "completed" by submitting its quiz, unchanged.)
            return false;
        }

        return match ($lesson->content_type) {
            // The DELIBERATE FAIL-OPEN of videoEarned() below, expressed
            // where it belongs. A video whose duration was never probed has
            // no honest denominator, so there is nothing to measure — and
            // TASK-165 §2 puts exactly that case on the button side rather
            // than auto-completing it off the first position ping. The gate
            // itself still fails OPEN (see videoEarned), so the button
            // works; this only decides who pulls the trigger.
            ModuleContentType::Video => $this->hasMeasurableDuration($lesson),
            ModuleContentType::Pdf => true,
            default => false,
        };
    }

    /**
     * ADR-028 §2.3 — the ORIGINAL gate, unchanged: has this learner
     * actually watched/read the lesson's content?
     *
     * Also, per ADR-029 §2.2, the answer to "may this learner see and
     * attempt the quiz yet?" — the quiz opens exactly when the content gate
     * is met, and not before.
     */
    public function isContentEarned(ModuleLesson $lesson, User $agent): bool
    {
        // TASK-165 — the three "we cannot measure this" early returns that
        // used to be written out here now live in isMeasurable(), so the
        // resource and this gate answer from one implementation. Behaviour
        // is unchanged: an unmeasurable lesson passes the CONTENT half of
        // the gate and the button remains its trigger.
        if (! $this->isMeasurable($lesson)) {
            return true;
        }

        // withoutGlobalScopes + an explicit, fully server-resolved pair of
        // keys: this same gate runs inside the ADMIN override path, where
        // TenantScope would be evaluated against the ADMIN's company
        // rather than the learner's. Both keys here come from records the
        // caller already authorized, so nothing widens — see
        // VideoProcessingSettingService for the same reasoning.
        $progress = ModuleLessonProgress::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('module_lesson_id', $lesson->id)
            ->first();

        return match ($lesson->content_type) {
            ModuleContentType::Video => $this->videoEarned($lesson, $progress),
            ModuleContentType::Pdf => $this->pdfEarned($lesson, $progress),
            default => true,
        };
    }

    /**
     * ADR-029 §2.6 — "Failing blocks completion, but the admin decides, per
     * lesson."
     *
     * Three ways this is satisfied, and the ORDER matters for cost: the two
     * cheap in-memory answers come first so the extra query only runs for a
     * lesson that is genuinely quiz-blocked.
     *
     * 1. `quiz_blocks_completion` is false — the quiz is advisory. The
     *    attempt is still recorded for the admin (§2.6), it simply does not
     *    gate anything. This is the DEFAULT (see the migration), which is
     *    what stops every pre-ADR-029 lesson that already had authored
     *    questions from suddenly becoming uncompletable.
     * 2. The lesson has no questions — a "blocking" flag on a lesson with
     *    nothing to answer would be an unpassable gate, i.e. a permanent
     *    lockout produced by an admin checkbox with no visible cause.
     * 3. The learner has a passing attempt on record.
     *
     * `passed` is read from the stored attempt, never recomputed: the pass
     * mark is admin-editable (BR-7), and re-deriving it here would let
     * raising the threshold silently un-pass a learner who had already
     * cleared the lesson.
     */
    public function isQuizSatisfied(ModuleLesson $lesson, User $agent): bool
    {
        if (! $lesson->quiz_blocks_completion) {
            return true;
        }

        /*
         * ADR-030 §2.1 — the questions hang off the QUIZ now, so "has this
         * lesson a quiz at all?" is the first question, and a lesson with no
         * quiz falls into case 2 below (a blocking flag with nothing to
         * answer must not become a permanent lockout).
         *
         * This also settles what UNLINKING does to the gate (§2.3): detach a
         * quiz and the lesson stops being quiz-blocked, immediately and
         * without a second flag to remember to clear. Recorded attempts are
         * untouched and still point at the lesson.
         */
        if ($lesson->quiz_id === null) {
            return true;
        }

        // withoutGlobalScopes for the same reason as every other query in
        // this class: the lesson id is already server-resolved and
        // authorized, and TenantScope here would be evaluated against
        // whoever happens to be authenticated (an admin, or nobody in a
        // queued context) rather than against the lesson.
        $hasQuestions = ModuleLessonQuizQuestion::withoutGlobalScopes()
            ->where('quiz_id', $lesson->quiz_id)
            ->exists();

        if (! $hasQuestions) {
            return true;
        }

        return $this->hasPassedQuiz($lesson, $agent);
    }

    /**
     * ADR-029 — has this learner a PASSING attempt on record for this
     * lesson?
     *
     * Split out from isQuizSatisfied() because the two answer different
     * questions and only coincide when the quiz is blocking: "may this
     * lesson be completed?" is true for an advisory quiz the learner
     * failed, while "did they pass?" is not. ModuleLessonResource needs the
     * latter for its `quiz_passed` field.
     *
     * `passed` is READ, never recomputed — the pass mark is admin-editable
     * (BR-7), and re-deriving it here would let raising the threshold
     * silently un-pass a learner who had already cleared the lesson.
     *
     * withoutGlobalScopes + fully server-resolved keys, for the same reason
     * as the progress lookup above: this runs inside the ADMIN override
     * path too, where TenantScope would be evaluated against the ADMIN's
     * company rather than the learner's. Both keys come from records the
     * caller already authorized, so this narrows rather than widens.
     */
    public function hasPassedQuiz(ModuleLesson $lesson, User $agent): bool
    {
        return ModuleLessonQuizAttempt::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('module_lesson_id', $lesson->id)
            ->where('passed', true)
            ->exists();
    }

    /**
     * ADR-028 §4 (human decision, 2026-08-08): tell a blocked learner what
     * to DO, never how far they got.
     *
     * "กรุณาดูวิดีโอให้ครบก่อนจึงจะกดเรียนจบได้" is actionable.
     * "ดูไปแล้ว 62% จาก 80%" tells them exactly how little they can get
     * away with, so it — and every other form of the number — is
     * deliberately absent from this message AND from every field of the
     * response that carries it. The recorded figure is visible only on the
     * Admin readout (GET /module-lessons/{lesson}/progress).
     *
     * ADR-029 keeps that rule: the quiz variant below names the ACTION
     * ("pass the quiz first") and carries neither the pass percentage nor
     * the learner's own score. `$agent` is required so the message can say
     * which half of the gate is unmet — that is a boolean about which
     * BUTTON to press next, not a measurement of how close they are.
     */
    public function blockedMessage(ModuleLesson $lesson, User $agent): string
    {
        if ($this->isContentEarned($lesson, $agent) && ! $this->isQuizSatisfied($lesson, $agent)) {
            return 'กรุณาทำแบบทดสอบท้ายบทให้ผ่านก่อนจึงจะกดเรียนจบได้';
        }

        return match ($lesson->content_type) {
            ModuleContentType::Video => 'กรุณาดูวิดีโอให้ครบก่อนจึงจะกดเรียนจบได้',
            default => 'กรุณาอ่านเอกสารให้ครบก่อนจึงจะกดเรียนจบได้',
        };
    }

    /**
     * TASK-165 — "is there an honest denominator for this video?", the one
     * condition behind both the fail-open in videoEarned() and the Video
     * arm of isMeasurable(). Extracted so the two can never disagree about
     * what an unprobed video is.
     */
    private function hasMeasurableDuration(ModuleLesson $lesson): bool
    {
        return $lesson->duration_seconds !== null && $lesson->duration_seconds > 0;
    }

    private function videoEarned(ModuleLesson $lesson, ?ModuleLessonProgress $progress): bool
    {
        if (! $this->hasMeasurableDuration($lesson)) {
            /*
             * DELIBERATE FAIL-OPEN, flagged for ag-lead.
             *
             * duration_seconds is probed by CompressUploadedVideo via
             * ffprobe. If that binary is missing (TASK-093: shared hosting
             * routinely has neither ffmpeg nor ffprobe on $PATH) we have no
             * honest denominator, so there is no percentage to check.
             *
             * The alternative — fail closed — would silently make EVERY
             * video lesson uncompletable on such a host, i.e. it would
             * block the BR-1 certification path for a whole company
             * because of our own infrastructure gap. ADR-028 §5 R1 names
             * "the gate locks out real learners on day one" as the top
             * risk of this sprint, so we take the weaker gate over the
             * outage and make the cause visible: a null duration_seconds
             * on an uploaded video lesson is the signal that ffprobe needs
             * installing (see SETUP.md).
             */
            return true;
        }

        if (! $progress || $progress->max_position_seconds === null) {
            return false;
        }

        $percent = $this->settings->forCompany($lesson->company_id)['video_watch_percent'];

        // ceil so the threshold is never accidentally rounded DOWN into
        // "80% means 79% is fine". Not money — BR-3 does not apply.
        $required = (int) ceil((int) $lesson->duration_seconds * $percent / 100);

        return $progress->max_position_seconds >= $required;
    }

    private function pdfEarned(ModuleLesson $lesson, ?ModuleLessonProgress $progress): bool
    {
        // Fail CLOSED here, unlike video: the reader reports its page
        // position the moment a PDF is opened (TASK-144), so "no progress
        // row at all" means the document was never opened — not that we
        // lack the means to measure it.
        if (! $progress || $progress->max_page === null) {
            return false;
        }

        // Prefer the count WE measured with pdfinfo at upload time.
        // Falling back to the client's own report is a real weakening —
        // a client that under-reports its page count shrinks its own
        // denominator — so the server's number wins whenever we have one,
        // and ModuleLessonProgressService keeps the fallback monotonic.
        $totalPages = $lesson->page_count ?: $progress->total_pages;

        if (! $totalPages) {
            return false;
        }

        $percent = $this->settings->forCompany($lesson->company_id)['pdf_read_percent'];
        $required = (int) ceil($totalPages * $percent / 100);

        return $progress->max_page >= $required;
    }
}
