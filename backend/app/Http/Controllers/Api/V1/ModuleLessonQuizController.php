<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\AttachQuizToLessonRequest;
use App\Http\Resources\ModuleLessonResource;
use App\Http\Resources\QuizResource;
use App\Models\ModuleLesson;
use App\Models\Quiz;
use App\Services\Academy\QuizService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * TASK-150 / ADR-030 §2.3/§2.5 — the LINK between a lesson and a library
 * quiz: what may be offered, attaching, and unlinking.
 *
 * Separate from QuizController because the resource being changed is the
 * LESSON (`module_lessons.quiz_id`), not the quiz — which is also why every
 * action here is authorized as lesson authoring (ModulePolicy::update on the
 * parent Section) rather than through QuizPolicy.
 */
class ModuleLessonQuizController extends Controller
{
    /**
     * ADR-030 §2.5 — "The lesson's quiz selector lists unattached quizzes in
     * the same company, plus the one currently attached. A quiz already
     * taken by another lesson must not appear as a choice that then fails —
     * the UI should not teach the rule by rejecting the user."
     *
     * So this endpoint exists specifically so the picker CANNOT offer an
     * impossible choice. Gated on `update`, not `view`: it is an authoring
     * affordance, and it exposes the company's whole quiz library.
     */
    public function available(ModuleLesson $moduleLesson, QuizService $service): AnonymousResourceCollection
    {
        $this->authorize('update', $moduleLesson->module);

        return QuizResource::collection(
            $service->availableFor($moduleLesson)->loadCount('questions')->load('moduleLesson')
        );
    }

    /**
     * Attach (or re-attach) a quiz. Authorization + the exclusivity check
     * live in AttachQuizToLessonRequest; the race-safe re-check and the
     * audit entry live in QuizService::attach().
     */
    public function attach(AttachQuizToLessonRequest $request, ModuleLesson $moduleLesson, QuizService $service): ModuleLessonResource
    {
        // withoutGlobalScopes: the quiz id was just validated to belong to
        // the LESSON's company, which is the tenant that matters here. A
        // Super Admin acting inside another company would otherwise be fine,
        // but a Company Admin's TenantScope and the lesson's company are the
        // same thing anyway — this simply removes the ambiguity.
        $quiz = Quiz::withoutGlobalScopes()->findOrFail($request->integer('quiz_id'));

        $lesson = $service->attach($moduleLesson, $quiz, $request->user());

        return new ModuleLessonResource($lesson->load(['quiz', 'quizQuestions.options']));
    }

    /**
     * ADR-030 §2.3 — unlink. The quiz returns to the library intact
     * (questions and all); recorded attempts stay pointed at the LESSON and
     * are not touched, because an attempt is a record of a learner doing a
     * lesson (see QuizService::detach()).
     */
    public function detach(Request $request, ModuleLesson $moduleLesson, QuizService $service): ModuleLessonResource
    {
        $this->authorize('update', $moduleLesson->module);

        $lesson = $service->detach($moduleLesson, $request->user());

        return new ModuleLessonResource($lesson->load(['quiz', 'quizQuestions.options']));
    }
}
