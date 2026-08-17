<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreModuleLessonQuizQuestionRequest;
use App\Http\Requests\Academy\StoreQuizQuestionRequest;
use App\Http\Requests\Academy\UpdateModuleLessonQuizQuestionRequest;
use App\Http\Resources\ModuleLessonQuizQuestionResource;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonQuizQuestion;
use App\Models\Quiz;
use App\Services\Academy\ModuleLessonQuizQuestionService;
use App\Services\Academy\QuizService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Mirrors ExamQuestionController exactly — no dedicated Policy, reuses
 * ModulePolicy via the parent Module (Section) for the LESSON-scoped
 * routes, and QuizPolicy for the LIBRARY-scoped ones. index() is gated to
 * `update` (admin-only), same reasoning as ExamQuestionController's own
 * comment: this listing is authoring-only tooling and always includes
 * `is_correct`; agents take the quiz through the masked view embedded in
 * ModuleLessonResource instead.
 *
 * ADR-030 §2.1 (TASK-150) — a question hangs off a QUIZ now. That gives two
 * entry points, which is the point of the ADR rather than duplication:
 *
 *   - `/module-lessons/{lesson}/quiz-questions` — the ORIGINAL path, still
 *     the default (§3: "create a new quiz right here"). It creates and
 *     attaches a quiz on first use, so an admin who never opens the library
 *     sees no change whatsoever.
 *   - `/quizzes/{quiz}/questions` — the LIBRARY path: author a quiz before
 *     any lesson exists, which is the entire reason ADR-030 was written.
 *
 * Both funnel into the same Service, so the two can never diverge.
 */
class ModuleLessonQuizQuestionController extends Controller
{
    public function index(ModuleLesson $moduleLesson): AnonymousResourceCollection
    {
        $this->authorize('update', $moduleLesson->module);

        // Unchanged for callers: ModuleLesson::quizQuestions() now hops
        // through `quiz_id`, and yields an empty collection for a lesson
        // with no quiz.
        return ModuleLessonQuizQuestionResource::collection($moduleLesson->quizQuestions()->with('options')->get());
    }

    public function store(
        StoreModuleLessonQuizQuestionRequest $request,
        ModuleLesson $moduleLesson,
        ModuleLessonQuizQuestionService $service,
        QuizService $quizzes,
    ): ModuleLessonQuizQuestionResource {
        // ADR-030 §3 — the lesson gets its own quiz the first time somebody
        // types a question into it, named after the lesson, exactly as the
        // §2.2 data migration named the quizzes it created.
        $quiz = $quizzes->ensureForLesson($moduleLesson, $request->user());

        $question = $service->create($quiz, $request->validated());

        return new ModuleLessonQuizQuestionResource($question->load('options'));
    }

    /** ADR-030 §2.1 — the library-scoped listing: a quiz with no lesson still has questions. */
    public function indexForQuiz(Quiz $quiz): AnonymousResourceCollection
    {
        $this->authorize('update', $quiz);

        return ModuleLessonQuizQuestionResource::collection($quiz->questions()->with('options')->get());
    }

    /** ADR-030 §2.1 — author straight into the library, before any lesson exists. */
    public function storeForQuiz(
        StoreQuizQuestionRequest $request,
        Quiz $quiz,
        ModuleLessonQuizQuestionService $service,
    ): ModuleLessonQuizQuestionResource {
        $question = $service->create($quiz, $request->validated());

        return new ModuleLessonQuizQuestionResource($question->load('options'));
    }

    public function update(UpdateModuleLessonQuizQuestionRequest $request, ModuleLessonQuizQuestion $moduleLessonQuizQuestion, ModuleLessonQuizQuestionService $service): ModuleLessonQuizQuestionResource
    {
        $question = $service->update($moduleLessonQuizQuestion, $request->validated());

        return new ModuleLessonQuizQuestionResource($question->load('options'));
    }

    public function destroy(ModuleLessonQuizQuestion $moduleLessonQuizQuestion): Response
    {
        // ADR-030 §2.1 — authorized through the QUIZ, not the lesson: the
        // question may belong to a library quiz that no lesson holds, in
        // which case there is no Module to ask. QuizPolicy::update grants
        // the same set of actors ModulePolicy::update did (Super Admin, or
        // Company Admin of the same company), so nothing widens.
        $this->authorize('update', $moduleLessonQuizQuestion->quiz);

        app(ModuleLessonQuizQuestionService::class)->delete($moduleLessonQuizQuestion);

        return response()->noContent();
    }
}
