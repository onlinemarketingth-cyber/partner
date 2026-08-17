<?php

namespace App\Http\Resources;

use App\Enums\MediaSourceType;
use App\Enums\ModuleContentType;
use App\Services\Academy\LessonAccessGate;
use App\Services\Academy\LessonCompletionGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ADR-009 — carries the exact per-item shape the old ModuleResource
// used to expose directly (content_type/source_type/content_ref/
// stream_url/processing_status), now scoped to one Lesson within a
// Section.
//
// ADR-028 §2.1/§2.2 (TASK-142) — the same nulling rule now covers ANY
// uploaded content type, not only video, and the resource carries the
// downloadability flag plus the two URLs the UI needs.
//
// ADR-029 §2.1/§2.2 (TASK-149) — quiz_questions is no longer restricted to
// content_type=quiz, and the quiz is gated behind the ADR-028 content gate.
class ModuleLessonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isUpload = $this->source_type === MediaSourceType::Upload;
        $isUploadedFile = $isUpload && in_array($this->content_type, ModuleContentType::uploadable(), true);

        $user = $request->user();
        $isAdmin = $user && ($user->isSuperAdmin() || $user->isCompanyAdmin());

        $questions = $this->quizQuestions;
        $questionCount = $questions->count();

        /*
         * ADR-029 §2.2 — "The quiz appears only after the content gate is
         * met." COMPUTED HERE, SERVER-SIDE, from recorded progress; the
         * client is never asked to decide it (ADR-028 §3 rejected trusting
         * the client for exactly this class of judgement).
         *
         * It reads the CONTENT half of the gate specifically
         * (isContentEarned), not isEarned — the full gate now also asks
         * whether the quiz was passed, which would make this circular.
         *
         * An admin is always unlocked: they are authoring/previewing, not
         * learning, and they already see the answers a page below.
         *
         * Cost note: this is one small query per lesson that HAS questions,
         * and none at all for a lesson that does not (the `$questionCount`
         * short-circuit is deliberate, not incidental).
         */
        /*
         * TASK-151 / ADR-031 §2.2, §2.3 — is this lesson OPEN to this
         * learner yet?
         *
         * A RENDERING HINT ONLY. The rule itself is enforced on the stream
         * route, the completion POST, the progress PUT and the quiz-attempt
         * POST (see LessonAccessGate's docblock) — §2.2: "a client-side lock
         * is decoration". If this field disappeared tomorrow the gate would
         * still hold; the learner would just meet it as an error instead of
         * a padlock.
         *
         * Costs nothing for a Section with neither control switched on (the
         * gate short-circuits before any query), and one memoised pair of
         * queries per Section for one that has.
         */
        $access = app(LessonAccessGate::class);
        $lockReason = $access->reasonFor($this->resource, $user);

        $quizUnlocked = $questionCount > 0
            && $user !== null
            // ADR-031 §2.2 — a LOCKED lesson hands out no questions. Without
            // this, the §2.2 lock would be visible on the row while its quiz
            // sat open beside it, and for a `link` or downloadable lesson
            // (which satisfy isContentEarned trivially — ADR-028 §2.3) that
            // is the whole gate, bypassed by reading one field.
            && $lockReason === null
            && ($isAdmin || app(LessonCompletionGate::class)->isContentEarned($this->resource, $user));

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'module_id' => $this->module_id,
            'title' => $this->title,
            'content_type' => $this->content_type?->value,
            'source_type' => $this->source_type?->value,
            // ADR-007/ADR-028 — content_ref is exposed as-is for an
            // EXTERNAL pdf/image/link and an EMBEDDED video (all already
            // public URLs), but never for anything we store ourselves
            // (our own private-disk path — §5 rule 6 — the stream route is
            // the only access path) or a quiz lesson (no content_ref).
            'content_ref' => $isUploadedFile ? null : $this->content_ref,
            'stream_url' => $isUploadedFile ? route('module-lessons.stream', $this->id) : null,
            /*
             * ADR-028 §2.2 — the URL the in-app PLAYER/READER should use.
             *
             * stream_url obeys the spec literally: it serves
             * Content-Disposition: attachment once is_downloadable is on,
             * which is what a download button wants and exactly what a
             * <video> element does not. `?inline=1` asks for inline
             * regardless, and the stream endpoint only honours it when
             * is_downloadable is true — i.e. only for a file the company
             * has already decided the learner may keep, so it grants
             * nothing the download button did not already grant.
             *
             * ag-ui: render from inline_url, download from stream_url.
             */
            'inline_url' => $isUploadedFile
                ? route('module-lessons.stream', $this->id).($this->is_downloadable ? '?inline=1' : '')
                : null,
            'is_downloadable' => (bool) $this->is_downloadable,
            'processing_status' => $this->processing_status?->value,
            // ADR-028 §2.3 — needed by the player for the resume
            // affordance (TASK-147). Null means "not probed" (an embed, or
            // ffprobe unavailable); it is NOT a completion percentage and
            // must never be presented as one to a learner (ADR-028 §4).
            'duration_seconds' => $this->duration_seconds,
            // ADR-028 §2.3 — server-measured (pdfinfo) page count, so the
            // reader can show "หน้า 4 / 12" without waiting to render the
            // whole document (TASK-144). Null for a non-PDF, an external
            // URL, or a host without poppler.
            'page_count' => $this->page_count,
            'sort_order' => $this->sort_order,
            'xp_reward' => $this->xp_reward,
            'is_published' => $this->is_published,

            /*
             * TASK-165 §3.1 — DOES THIS LESSON COMPLETE ITSELF?
             *
             * True: the server can measure this lesson, so completion is
             * recorded the moment the gate is satisfied and the client shows
             * NO completion control. False: the ADR-028 §2.3 fallback — the
             * learner presses the button and we take their word for it.
             *
             * THE CLIENT MUST NOT COMPUTE THIS from content_type +
             * source_type + is_downloadable. That predicate already exists,
             * once, in LessonCompletionGate::isMeasurable(), and this field
             * is literally its answer. A second copy in Vue is a copy that
             * drifts (TASK-159's `from`/`to` vs `color1`/`color2` gradient
             * mismatch is the standing example) and here the drift shows a
             * learner a button that 422s, or hides the only control that
             * could ever complete their lesson — on the BR-1 path.
             *
             * Costs nothing: isMeasurable() asks only about the lesson's own
             * columns and runs no query.
             */
            'completion_is_automatic' => app(LessonCompletionGate::class)->isMeasurable($this->resource),

            // ---- ADR-031, the sequencing block --------------------------
            //
            // §2.4 — "shown, not counted". The learner sees the lesson and
            // may take it; it is simply not in the denominator (see
            // ModuleResource::required_lesson_count) and never gates the
            // next required lesson.
            'is_optional' => (bool) $this->is_optional,
            /*
             * §2.2/§2.3 + §3 — locked, and WHY.
             *
             * The reason is separate from the boolean on purpose: ADR-031 §3
             * — "'ต้องเรียนบทก่อนหน้าให้จบก่อน' and 'จะเปิดในอีก 3 วัน' are
             * different problems for the learner". One goes and finishes a
             * lesson; the other waits.
             *
             * `lock_message` carries the ready-made Thai sentence and, per
             * ADR-028 §4, names the ACTION and no measurement — no
             * percentage, no threshold, no "you are 2 of 3 lessons away".
             *
             * `unlocks_at` is the Section's drip time, repeated on the
             * lesson row so the UI can render a countdown without joining
             * back to the parent. Null unless the Section is dripped.
             *
             * NOTE for ag-ui: `stream_url` / `inline_url` above are still
             * present on a locked lesson. That is deliberate — the server
             * answers 403 on them, and blanking a URL would put the client
             * in charge of the rule again. Do not render a player for a
             * lesson with `is_locked: true`.
             */
            'is_locked' => $lockReason !== null,
            'lock_reason' => $lockReason?->value,
            'lock_message' => $lockReason?->message(),
            'unlocks_at' => $access->unlocksAtForLesson($this->resource, $user)?->toIso8601String(),

            // ---- ADR-029, the quiz block -------------------------------
            //
            // A COUNT, not the content: it lets the UI say "this lesson has
            // a 5-question quiz, available once you finish the material"
            // without handing a locked learner the questions themselves.
            'quiz_question_count' => $questionCount,
            /*
             * ADR-030 §2.1 (TASK-150) — WHICH quiz this lesson holds, or
             * null. Not sensitive (it is an id and a title of the lesson's
             * own quiz, and the questions are still gated by `quiz_unlocked`
             * below), and the admin authoring screen needs it to show the
             * current selection next to the picker fed by
             * GET /module-lessons/{lesson}/available-quizzes (§2.5).
             */
            'quiz_id' => $this->quiz_id,
            $this->mergeWhen($isAdmin && $this->quiz_id !== null, fn () => [
                'quiz' => [
                    'id' => $this->quiz?->id,
                    'title' => $this->quiz?->title,
                ],
            ]),
            'quiz_unlocked' => $quizUnlocked,
            // ADR-029 §2.6 — whether failing blocks completion. The UI
            // needs it to distinguish "required" from "advisory"; it is a
            // property of the lesson, not a measurement of the learner.
            'quiz_blocks_completion' => (bool) $this->quiz_blocks_completion,
            // The learner's own standing. Null when there is no quiz, or
            // for an unauthenticated request. Their own result is not a
            // withheld number — the PASS MARK is, and it is exposed below
            // to admins only.
            // hasPassedQuiz, NOT isQuizSatisfied: the latter short-circuits
            // to true for an advisory quiz, which is the right answer for
            // "may this lesson be completed?" and the wrong one for a "you
            // passed" badge.
            'quiz_passed' => $questionCount > 0 && $user
                ? app(LessonCompletionGate::class)->hasPassedQuiz($this->resource, $user)
                : null,
            // ADR-029 §2.4 — the per-lesson OVERRIDE column (null = inherit
            // the company setting), for the authoring form. ADMIN ONLY: a
            // pass mark is a threshold, and ADR-028 §4 settled that
            // thresholds are not shown to learners — AcademyCompletionSetting
            // withholds the company-level one for the same reason.
            $this->mergeWhen($isAdmin, fn () => ['quiz_pass_percent' => $this->quiz_pass_percent]),

            /*
             * ADR-029 §2.1 — ANY lesson may carry questions now. The old
             * `when(content_type === Quiz)` gate is gone: it was the only
             * thing stopping a video or PDF lesson from having the
             * end-of-lesson quiz the feature was always named after.
             *
             * TWO restrictions remain, and they are different in kind:
             *
             *   - `is_correct` is masked to null for the Agent role, exactly
             *     as before (ADR-029 §2.7 — "never which answer is right").
             *   - The questions are withheld entirely until the quiz is
             *     unlocked (§2.2). Shipping them with a "don't render this
             *     yet" flag would put the gate in the client's hands, which
             *     is the pattern ADR-028 §3 rejected. `quiz_question_count`
             *     above still tells the UI a quiz exists.
             */
            'quiz_questions' => $this->when(
                $quizUnlocked,
                fn () => $questions->map(fn ($q) => [
                    'id' => $q->id,
                    'question_text' => $q->question_text,
                    'sort_order' => $q->sort_order,
                    'options' => $q->options->map(fn ($o) => [
                        'id' => $o->id,
                        'option_text' => $o->option_text,
                        'is_correct' => $isAdmin ? $o->is_correct : null,
                        'sort_order' => $o->sort_order,
                    ]),
                ]),
            ),
        ];
    }
}
