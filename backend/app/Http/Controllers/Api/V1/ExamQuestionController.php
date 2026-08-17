<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreExamQuestionRequest;
use App\Http\Requests\Academy\UpdateExamQuestionRequest;
use App\Http\Resources\ExamQuestionResource;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Services\Academy\ExamQuestionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// Academy Sprint 1 — no dedicated Policy: reuses ExamPolicy exactly like
// ProductSpecController reuses ProductPolicy (see that controller's own
// comment for the reasoning). Authorization lives in the Form Requests
// (StoreExamQuestionRequest/UpdateExamQuestionRequest).
class ExamQuestionController extends Controller
{
    /**
     * Authoring-only listing (always includes is_correct via
     * ExamQuestionResource) — gated to `update`, NOT `view`, since
     * ExamPolicy::view also allows the Agent role to see exam metadata.
     * Agents take the exam through GET /exams/{exam} instead, whose
     * ExamResource masks is_correct per-request (see that Resource).
     */
    public function index(Exam $exam): AnonymousResourceCollection
    {
        $this->authorize('update', $exam);

        return ExamQuestionResource::collection($exam->questions()->with('options')->get());
    }

    public function store(StoreExamQuestionRequest $request, Exam $exam, ExamQuestionService $service): ExamQuestionResource
    {
        $question = $service->create($exam, $request->validated());

        return new ExamQuestionResource($question->load('options'));
    }

    public function update(UpdateExamQuestionRequest $request, ExamQuestion $examQuestion, ExamQuestionService $service): ExamQuestionResource
    {
        $question = $service->update($examQuestion, $request->validated());

        return new ExamQuestionResource($question->load('options'));
    }

    public function destroy(ExamQuestion $examQuestion, ExamQuestionService $service): Response
    {
        $this->authorize('update', $examQuestion->exam);

        $service->delete($examQuestion);

        return response()->noContent();
    }
}
