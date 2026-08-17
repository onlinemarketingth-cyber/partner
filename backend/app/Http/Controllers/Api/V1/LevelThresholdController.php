<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gamification\StoreLevelThresholdRequest;
use App\Http\Requests\Gamification\UpdateLevelThresholdRequest;
use App\Http\Resources\LevelThresholdResource;
use App\Models\LevelThreshold;
use App\Services\Gamification\LevelThresholdService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

// Phase 9 — BR-7 config CRUD for the XP->Level curve. Platform-wide
// (no company_id — see LevelThresholdPolicy), so no per-tenant
// filtering is needed in index(), unlike GamificationRuleController.
// Small table, no pagination.
class LevelThresholdController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(LevelThreshold::class, 'level_threshold');
    }

    public function index(): AnonymousResourceCollection
    {
        return LevelThresholdResource::collection(LevelThreshold::orderBy('level_number')->get());
    }

    public function store(StoreLevelThresholdRequest $request, LevelThresholdService $service): LevelThresholdResource
    {
        return new LevelThresholdResource($service->create($request->validated()));
    }

    public function show(LevelThreshold $levelThreshold): LevelThresholdResource
    {
        return new LevelThresholdResource($levelThreshold);
    }

    public function update(UpdateLevelThresholdRequest $request, LevelThreshold $levelThreshold, LevelThresholdService $service): LevelThresholdResource
    {
        return new LevelThresholdResource($service->update($levelThreshold, $request->validated()));
    }

    public function destroy(LevelThreshold $levelThreshold, LevelThresholdService $service): Response
    {
        $service->delete($levelThreshold);

        return response()->noContent();
    }
}
