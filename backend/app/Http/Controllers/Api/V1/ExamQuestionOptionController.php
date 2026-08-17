<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreExamQuestionOptionRequest;
use App\Http\Requests\Academy\UpdateExamQuestionOptionRequest;
use App\Http\Resources\ExamQuestionOptionResource;
use App\Models\ExamQuestion;
use App\Models\ExamQuestionOption;
use App\Services\Academy\ExamQuestionOptionService;
use Illuminate\Http\Response;

class ExamQuestionOptionController extends Controller
{
    public function store(StoreExamQuestionOptionRequest $request, ExamQuestion $examQuestion, ExamQuestionOptionService $service): ExamQuestionOptionResource
    {
        $option = $service->create($examQuestion, $request->validated());

        return new ExamQuestionOptionResource($option);
    }

    public function update(UpdateExamQuestionOptionRequest $request, ExamQuestionOption $examQuestionOption, ExamQuestionOptionService $service): ExamQuestionOptionResource
    {
        $option = $service->update($examQuestionOption, $request->validated());

        return new ExamQuestionOptionResource($option);
    }

    public function destroy(ExamQuestionOption $examQuestionOption, ExamQuestionOptionService $service): Response
    {
        $this->authorize('update', $examQuestionOption->examQuestion->exam);

        $service->delete($examQuestionOption);

        return response()->noContent();
    }
}
