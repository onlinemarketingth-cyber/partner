<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sales\MeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// TASK-053 / ADR-016 Phase 2 — the Agent Portal personal home hub
// aggregation. Always self-scoped to the authenticated user (no {user}
// param exists), same "personal, never someone else's" contract as the
// notification bell.
class MeController extends Controller
{
    public function home(Request $request, MeService $service): JsonResponse
    {
        return response()->json(['data' => $service->home($request->user())]);
    }

    public function tasks(Request $request, MeService $service): JsonResponse
    {
        return response()->json(['data' => $service->tasks($request->user())]);
    }
}
