<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreModuleLessonQuizAttemptRequest;
use App\Http\Resources\ModuleLessonQuizAttemptResource;
use App\Http\Resources\ModuleLessonQuizAttemptResultResource;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonQuizAttempt;
use App\Services\Academy\ModuleLessonQuizAttemptService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * TASK-149 / ADR-029 — the graded end-of-lesson quiz.
 *
 * Two endpoints with deliberately asymmetric audiences, the same shape
 * ModuleLessonProgressController already uses for this lesson's progress:
 *
 *   POST /module-lessons/{lesson}/quiz-attempts — the LEARNER submits
 *        answers and gets back per-question feedback about their OWN
 *        answers (ADR-029 §2.7).
 *   GET  /module-lessons/{lesson}/quiz-attempts — the ADMIN reads who
 *        attempted, what they scored and whether they passed (§2.5). Score
 *        only, never the chosen answers (§4 item 2, unresolved).
 *
 * Append-only: no update/destroy action and no route for one. No dedicated
 * Policy, same convention as ModuleLessonController and
 * ModuleLessonQuizQuestionController — a lesson's authorization is always a
 * question about its parent Section (ModulePolicy).
 */
class ModuleLessonQuizAttemptController extends Controller
{
    /**
     * The ADMIN readout. Authorized on ModulePolicy::update (Company Admin
     * own company / Super Admin), NOT ::view — an Agent may view a module in
     * order to learn from it and must not be able to read other learners'
     * results. Identical reasoning to
     * ModuleLessonProgressController::index().
     *
     * Cross-tenant is already 404 at route-model binding (TenantScope,
     * §5 rule 5), and the attempt query is TenantScope'd in its own right.
     */
    public function index(ModuleLesson $moduleLesson): AnonymousResourceCollection
    {
        $this->authorize('update', $moduleLesson->module);

        $attempts = ModuleLessonQuizAttempt::query()
            ->with('user')
            ->where('module_lesson_id', $moduleLesson->id)
            ->orderByDesc('attempted_at')
            ->paginate();

        return ModuleLessonQuizAttemptResource::collection($attempts);
    }

    /**
     * The LEARNER submits. Authorization + payload validation live in
     * StoreModuleLessonQuizAttemptRequest; grading, the ADR-029 §2.2 unlock
     * check and the BR-6 company re-check live in the Service. This method
     * is thin on purpose (CLAUDE.md §7).
     */
    public function store(
        StoreModuleLessonQuizAttemptRequest $request,
        ModuleLesson $moduleLesson,
        ModuleLessonQuizAttemptService $service,
    ): ModuleLessonQuizAttemptResultResource {
        $graded = $service->attempt($moduleLesson, $request->user(), $request->validated('answers'));

        return new ModuleLessonQuizAttemptResultResource($graded);
    }
}
