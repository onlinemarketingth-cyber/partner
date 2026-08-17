<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreModuleLessonQuizOptionRequest;
use App\Http\Requests\Academy\UpdateModuleLessonQuizOptionRequest;
use App\Http\Resources\ModuleLessonQuizOptionResource;
use App\Models\ModuleLessonQuizOption;
use App\Models\ModuleLessonQuizQuestion;
use App\Services\Academy\ModuleLessonQuizOptionService;
use Illuminate\Http\Response;

// Mirrors ExamQuestionOptionController exactly.
class ModuleLessonQuizOptionController extends Controller
{
    public function store(StoreModuleLessonQuizOptionRequest $request, ModuleLessonQuizQuestion $moduleLessonQuizQuestion, ModuleLessonQuizOptionService $service): ModuleLessonQuizOptionResource
    {
        $option = $service->create($moduleLessonQuizQuestion, $request->validated());

        return new ModuleLessonQuizOptionResource($option);
    }

    public function update(UpdateModuleLessonQuizOptionRequest $request, ModuleLessonQuizOption $moduleLessonQuizOption, ModuleLessonQuizOptionService $service): ModuleLessonQuizOptionResource
    {
        $option = $service->update($moduleLessonQuizOption, $request->validated());

        return new ModuleLessonQuizOptionResource($option);
    }

    public function destroy(ModuleLessonQuizOption $moduleLessonQuizOption): Response
    {
        // ADR-030 §2.1 — through the QUIZ, not the lesson's Module: an
        // option can belong to a library quiz that no lesson holds.
        $this->authorize('update', $moduleLessonQuizOption->moduleLessonQuizQuestion->quiz);

        app(ModuleLessonQuizOptionService::class)->delete($moduleLessonQuizOption);

        return response()->noContent();
    }
}
