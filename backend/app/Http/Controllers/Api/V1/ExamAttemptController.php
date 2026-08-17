<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\StoreExamAttemptRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Services\Academy\ExamAttemptService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExamAttemptController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExamAttempt::class);

        $query = ExamAttempt::query()->with('exam');

        if ($request->user()->isAgent()) {
            $query->where('user_id', $request->user()->id);
        }

        return ExamAttemptResource::collection($query->latest('attempted_at')->paginate());
    }

    public function store(StoreExamAttemptRequest $request, ExamAttemptService $service): ExamAttemptResource
    {
        $exam = Exam::findOrFail($request->validated('exam_id'));

        $attempt = $service->attempt($exam, $request->user(), $request->validated('answers') ?? []);

        return new ExamAttemptResource($attempt->load('exam'));
    }
}
