<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreQuizRequest;
use App\Http\Requests\Academy\UpdateQuizRequest;
use App\Http\Resources\QuizResource;
use App\Models\Quiz;
use App\Services\Academy\QuizService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * TASK-150 / ADR-030 — CRUD for the quiz library.
 *
 * Thin per §7: TenantScope narrows every query (BR-6), QuizPolicy decides
 * who may ask (admin-only — see the Policy for why an Agent is excluded),
 * the Form Requests validate, and QuizService owns the one real business
 * rule here (§2.4: a linked quiz cannot be deleted).
 */
class QuizController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Quiz::class, 'quiz');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return QuizResource::collection(
            Quiz::query()
                ->withCount('questions')
                // Eager-loaded so `is_attached` costs one query for the page
                // rather than one per row.
                ->with('moduleLesson')
                /*
                 * ADR-030 §3 — "the library will accumulate orphans
                 * (authored, never attached). Show them as such." This is
                 * the filter that lets an admin see just those: the same
                 * "not spoken for" predicate as QuizService::availableFor(),
                 * minus the "plus the currently attached one" clause, which
                 * only makes sense relative to a specific lesson.
                 *
                 * Soft-deleted lessons count as holders here too — see
                 * Quiz::moduleLesson()'s docblock.
                 */
                ->when($request->boolean('unattached'), fn ($query) => $query->whereDoesntHave('moduleLesson'))
                ->orderBy('title')
                ->paginate()
                ->withQueryString()
        );
    }

    public function store(StoreQuizRequest $request, QuizService $service): QuizResource
    {
        $quiz = $service->create($request->validated(), $request->user());

        return new QuizResource($quiz->loadCount('questions')->load('moduleLesson'));
    }

    public function show(Quiz $quiz): QuizResource
    {
        return new QuizResource(
            $quiz->loadCount('questions')->load(['moduleLesson', 'questions.options'])
        );
    }

    public function update(UpdateQuizRequest $request, Quiz $quiz, QuizService $service): QuizResource
    {
        $quiz = $service->update($quiz, $request->validated());

        return new QuizResource($quiz->loadCount('questions')->load('moduleLesson'));
    }

    /**
     * ADR-030 §2.4 — refuses with a 422 (not a 403) when the quiz is linked:
     * the admin IS allowed to delete quizzes, this particular one just has a
     * lesson depending on it and they need to unlink first. QuizService
     * throws the ValidationException.
     */
    public function destroy(Quiz $quiz, QuizService $service): Response
    {
        $service->delete($quiz);

        return response()->noContent();
    }
}
