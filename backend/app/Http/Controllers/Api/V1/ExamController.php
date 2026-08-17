<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreExamRequest;
use App\Http\Requests\Academy\UpdateExamRequest;
use App\Http\Resources\ExamResource;
use App\Models\Exam;
use App\Services\Academy\ExamService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ExamController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Exam::class, 'exam');
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ExamResource::collection(Exam::query()->with('certTier')->paginate());
    }

    public function store(StoreExamRequest $request, ExamService $service): ExamResource
    {
        $exam = $service->create($request->validated(), $request->user());

        return new ExamResource($exam->load('certTier'));
    }

    public function show(Exam $exam): ExamResource
    {
        // Academy Sprint 1 — eager-load the question bank so agents can
        // actually take the exam from this response (see ExamResource's
        // `questions` masking of is_correct).
        return new ExamResource($exam->load(['certTier', 'questions.options']));
    }

    public function update(UpdateExamRequest $request, Exam $exam, ExamService $service): ExamResource
    {
        $exam = $service->update($exam, $request->validated());

        return new ExamResource($exam->load('certTier'));
    }

    public function destroy(Exam $exam): Response
    {
        $exam->delete();

        return response()->noContent();
    }
}
