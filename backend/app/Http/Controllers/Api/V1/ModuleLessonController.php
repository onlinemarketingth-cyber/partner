<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\ReorderModuleLessonsRequest;
use App\Http\Requests\Academy\StoreModuleLessonRequest;
use App\Http\Requests\Academy\UpdateModuleLessonRequest;
use App\Http\Resources\ModuleLessonResource;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Services\Academy\LessonAccessGate;
use App\Services\Academy\ModuleLessonService;
use App\Services\Academy\ModuleOrderService;
use App\Support\Media\RangeFileResponder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

// ADR-009 — no dedicated Policy: reuses ModulePolicy exactly like
// ExamQuestionController reuses ExamPolicy (see that controller's own
// comment for the reasoning) — a Lesson's authorization is always
// "can I `update` the parent Section (Module)".
class ModuleLessonController extends Controller
{
    /**
     * GET /module-lessons/{moduleLesson} — TASK-167 §3.
     *
     * The Agent Portal gives a lesson its own ROUTE, so it must be able to
     * fetch itself: a deep link or a refresh has no /modules payload to read
     * the lesson out of.
     *
     * ModulePolicy::view on the parent Section — the same check stream()
     * makes, because a Lesson's authorization is always its Section's
     * (see this class's docblock).
     *
     * TASK-155 — a draft lesson, or one inside a draft Section, does not
     * exist as far as an Agent is concerned. 404, not 403, for the same
     * reason ModuleController::show gives: distinguishing "no such lesson"
     * from "a lesson you may not see" is the IDOR-adjacent leak CLAUDE.md
     * §5.5 warns about. Admins are exempt — they are authoring it.
     *
     * A LOCKED (but published) lesson still answers 200 with the same
     * ModuleLessonResource the list serves: `is_locked` + `lock_message`,
     * and `quiz_questions` withheld. ADR-031 §4 item 2 chose
     * shown-and-greyed over hidden, and the four write/stream routes remain
     * the actual enforcement (LessonAccessGate).
     */
    public function show(Request $request, ModuleLesson $moduleLesson): ModuleLessonResource
    {
        $this->authorize('view', $moduleLesson->module);

        if ($request->user()?->isAgent() && (! $moduleLesson->is_published || ! $moduleLesson->module?->is_published)) {
            abort(404);
        }

        // Same eager loads the write actions use: ModuleLessonResource reads
        // quizQuestions for every lesson (ADR-029 §2.1) and LessonAccessGate
        // reads the parent Section off the relation (ADR-031 §2.2).
        return new ModuleLessonResource($moduleLesson->load(['module', 'quiz', 'quizQuestions.options']));
    }

    public function store(StoreModuleLessonRequest $request, Module $module, ModuleLessonService $service): ModuleLessonResource
    {
        $lesson = $service->create($module, $request->validated(), $request->file('file'));

        // ADR-029 §2.1 — ModuleLessonResource reads quizQuestions for every
        // lesson now, so it is loaded explicitly rather than lazily.
        // ADR-031 §2.2 — `module` likewise, so LessonAccessGate reads the
        // parent Section from the relation instead of re-querying it.
        return new ModuleLessonResource($lesson->load(['module', 'quiz', 'quizQuestions.options']));
    }

    public function update(UpdateModuleLessonRequest $request, ModuleLesson $moduleLesson, ModuleLessonService $service): ModuleLessonResource
    {
        // TASK-188 §6.D3(b) — the actor is passed through so a content_type
        // change can be audit-logged with "who did this" (CLAUDE.md §6), the
        // same way UserService::update() and QuizService::attach() take one.
        $lesson = $service->update($moduleLesson, $request->validated(), $request->file('file'), $request->user());

        // ADR-029 §2.1 — ModuleLessonResource reads quizQuestions for every
        // lesson now, so it is loaded explicitly rather than lazily.
        return new ModuleLessonResource($lesson->load(['module', 'quiz', 'quizQuestions.options']));
    }

    /**
     * GET /module-lessons/{moduleLesson}/content-type-change-impact —
     * TASK-188 §6.D3(a).
     *
     * What a content-type change will do to THIS lesson, before it is made,
     * so the confirmation dialog can state it instead of guessing: how many
     * learners lose recorded progress, how many keep a completion, whether a
     * stored file is about to be deleted, whether `is_downloadable` resets,
     * and whether the attached quiz survives (it does).
     *
     * ModulePolicy::update, not ::view — the counts are cross-learner
     * management data, the same audience and the same check as the ADR-028 §4
     * progress readout and the ADR-029 §2.5 attempt readout. An Agent asking
     * this about their own course would be asking how many of their
     * colleagues are behind.
     *
     * A plain array rather than a Resource: there is no model here, only
     * counts and booleans about a change that has not happened. CLAUDE.md §7's
     * "never return raw models" is satisfied by there being no model to leak —
     * every key is written out below by name.
     *
     * @return array{data: array<string, mixed>}
     */
    public function contentTypeChangeImpact(ModuleLesson $moduleLesson, ModuleLessonService $service): array
    {
        $this->authorize('update', $moduleLesson->module);

        return ['data' => $service->contentTypeChangeImpact($moduleLesson)];
    }

    /**
     * PUT /modules/{module}/lessons/reorder — TASK-151 / ADR-031 §2.1.
     *
     * The FULL ordered list of this Section's lesson ids, renumbered in ONE
     * transaction. Never N separate PUTs: a half-applied reorder (the tab
     * closed at lesson 7 of 20) is worse than no reorder, because nothing on
     * screen says it happened.
     *
     * Authorization: ReorderModuleLessonsRequest against ModulePolicy::update
     * on the route-bound (therefore TenantScope'd) Section. "Do these lessons
     * belong to THIS Section" is ModuleOrderService's job — the lesson routes
     * are flat, so a same-company lesson from another Section is a visible id
     * and only that check rejects it.
     */
    public function reorder(
        ReorderModuleLessonsRequest $request,
        Module $module,
        ModuleOrderService $service,
    ): AnonymousResourceCollection {
        $lessons = $service->reorderLessons($module, $request->validated('lesson_ids'), $request->user());

        return ModuleLessonResource::collection($lessons->load(['module', 'quiz', 'quizQuestions.options']));
    }

    public function destroy(ModuleLesson $moduleLesson): Response
    {
        $this->authorize('update', $moduleLesson->module);

        app(ModuleLessonService::class)->delete($moduleLesson);

        return response()->noContent();
    }

    /**
     * GET /module-lessons/{moduleLesson}/stream — ADR-007/ADR-009/ADR-028.
     *
     * Serves ANY uploaded lesson file (video, pdf, image — ADR-028 §2.1).
     * content_ref for those is our own private-disk path, never a public
     * URL (§5 rule 6). An EMBED-source video or an external pdf/link is
     * rendered client-side from content_ref and never routed through here.
     *
     * AUTHORIZATION RUNS FIRST, BEFORE ANY BYTES (ADR-028 §2.5, TASK-143
     * AC). The range handling below changes which bytes are returned; it
     * never changes whether they may be returned at all. Making the file
     * publicly reachable to make seeking easy would be a §5 rule 6
     * violation — see RangeFileResponder's class docblock.
     */
    public function stream(
        Request $request,
        ModuleLesson $moduleLesson,
        ModuleLessonService $service,
        LessonAccessGate $access,
    ): mixed {
        $this->authorize('view', $moduleLesson->module);

        abort_unless($moduleLesson->isUploadedFile(), 404);

        /*
         * TASK-151 / ADR-031 §2.2 — "a locked lesson's content must not be
         * streamable... a client-side lock is decoration, and this one is
         * on the BR-1 path (§6)."
         *
         * Placed with the other pre-byte checks and BEFORE
         * RangeFileResponder, for the same reason the authorize() above is:
         * the range handling decides WHICH bytes, never WHETHER.
         *
         * 403 with the reason, not 404: the lesson exists and the learner
         * is allowed to know it exists (ADR-031 §4 item 2 chose
         * shown-and-greyed over hidden — "hiding it makes the course look
         * shorter than it is"), they simply may not open it yet. The
         * message says what to DO and carries no measurement, consistent
         * with ADR-028 §4.
         */
        $lockReason = $access->reasonFor($moduleLesson, $request->user());

        abort_if($lockReason !== null, 403, $lockReason?->message() ?? '');

        return RangeFileResponder::respond(
            Storage::disk($service->disk()),
            $moduleLesson->content_ref,
            $request,
            $this->dispositionFor($moduleLesson, $request),
        );
    }

    /**
     * ADR-028 §2.2 — inline unless the file is downloadable, in which case
     * the browser is told to save it.
     *
     * `?inline=1` overrides that back to inline, and is honoured ONLY when
     * is_downloadable is already true. That combination grants nothing:
     * the learner may keep the file either way, and the in-app player /
     * PDF reader needs an inline response to render it. When
     * is_downloadable is false, inline is already the answer and there is
     * no way to ask for an attachment at all.
     *
     * To be explicit about what this flag is not (ADR-028 §2.2): it is
     * NOT protection. Once a browser renders a PDF it holds the bytes.
     * The flag raises friction and records intent.
     */
    private function dispositionFor(ModuleLesson $moduleLesson, Request $request): string
    {
        if (! $moduleLesson->is_downloadable) {
            return RangeFileResponder::DISPOSITION_INLINE;
        }

        return $request->boolean('inline')
            ? RangeFileResponder::DISPOSITION_INLINE
            : RangeFileResponder::DISPOSITION_ATTACHMENT;
    }
}
