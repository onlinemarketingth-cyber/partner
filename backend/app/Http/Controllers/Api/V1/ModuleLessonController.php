<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\ReorderModuleLessonsRequest;
use App\Http\Requests\Academy\StoreModuleLessonRequest;
use App\Http\Requests\Academy\UpdateModuleLessonRequest;
use App\Http\Resources\ModuleLessonResource;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\User;
use App\Services\Academy\LessonAccessGate;
use App\Services\Academy\ModuleLessonService;
use App\Services\Academy\ModuleOrderService;
use App\Support\Media\RangeFileResponder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
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
    /**
     * GET /module-lessons/{moduleLesson}/inline-stream?u=…&expires=…&signature=…
     *
     * The same bytes as stream() above, reachable by a `<video>` element.
     *
     * ── WHY A SECOND ROUTE AND NOT A FLAG ON THE FIRST ──
     *
     * stream() is protected by a header. A media element cannot send one —
     * not a cookie either, since the agent portal moved to bearer tokens
     * (ADR-039) — so an authenticated stream is unreachable from `<video
     * src>` no matter what the client does. Images and PDFs are fetched by
     * JS and were fixed on 2026-09-04 by sending the header by hand; video
     * cannot be, because the whole point is that the BROWSER issues the
     * ranged GETs (ADR-028 §2.5: seeking after downloading 200 MB is not
     * seeking).
     *
     * So the proof of identity moves into the URL. `signed` middleware
     * verifies our HMAC over every parameter — including `u` — before this
     * method runs. Two routes rather than one switch, so that "this one is
     * authenticated, that one is signed" is legible at the routes file
     * instead of being a branch somebody has to read this method to find.
     *
     * ── THE MOST DANGEROUS LINE IN THIS FILE ──
     *
     * `Auth::setUser()` below sets the acting user FROM A URL PARAMETER.
     * That is only safe because `signed` has already proved the URL — `u`
     * included — was minted by us and has not been edited; change one digit
     * and the signature fails before anything here executes. Remove the
     * `signed` middleware and this line becomes "log in as whoever asks".
     *
     * It is done this way ON PURPOSE rather than passing $learner down by
     * hand: setting the actor makes every downstream check — TenantScope,
     * the Module policy, LessonAccessGate — behave EXACTLY as it does on the
     * authenticated route. One authorization implementation, not two that
     * can drift. The alternative was re-deriving each check for a caller
     * with no actor, which is how a signed path quietly ends up more
     * permissive than the one it mirrors.
     */
    public function inlineStream(
        Request $request,
        ModuleLesson $moduleLesson,
        ModuleLessonService $service,
        LessonAccessGate $access,
    ): mixed {
        /*
         * A learner we cannot resolve is a refusal, never a fallback.
         *
         * LessonAccessGate::reasonFor() returns NULL — "not locked" — when
         * handed a null learner, which is correct for its own callers and
         * would be a hole here: an unresolvable `u` would sail past the lock
         * check. So the absence is caught at the door.
         *
         * withoutGlobalScopes because there is no actor yet for TenantScope
         * to scope by; the tenant check is the Module policy two lines down,
         * which is where it belongs.
         */
        $learner = User::withoutGlobalScopes()->find($request->integer('u'));

        abort_if($learner === null, 403);

        Auth::setUser($learner);

        abort_unless($moduleLesson->isUploadedFile(), 404);

        // The same two gates stream() runs, in the same order, now that the
        // actor is set: who may see this module at all, then whether this
        // particular lesson is open to them yet (ADR-031 §2.2).
        $this->authorize('view', $moduleLesson->module);

        $lockReason = $access->reasonFor($moduleLesson, $learner);

        abort_if($lockReason !== null, 403, $lockReason?->message() ?? '');

        /*
         * INLINE, always — unlike stream(), which serves an attachment for a
         * downloadable file and only honours `?inline=1` as an override. This
         * route exists solely to feed a player, and a Content-Disposition of
         * attachment is what stops one playing.
         */
        return RangeFileResponder::respond(
            Storage::disk($service->disk()),
            $moduleLesson->content_ref,
            $request,
            RangeFileResponder::DISPOSITION_INLINE,
        );
    }

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
