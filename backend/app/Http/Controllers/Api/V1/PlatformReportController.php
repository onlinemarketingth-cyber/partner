<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// TASK-041 (4.2) — Policy & Report IA item 4, cross-company report.
// Super-Admin-only (Section 5 — this is the one report that must see
// across every tenant, so it can't be gated by TenantScope/a per-
// company Policy the way everything else is). Read-only aggregate
// endpoint — mirrors ProductController::abcGrades()'s JsonResponse
// shape since the payload is a curated array per company, not a raw
// Eloquent model collection (Section 7 API Resource rule doesn't apply
// to this kind of computed report — same reasoning as abcGrades()).
class PlatformReportController extends Controller
{
    public function index(Request $request, PlatformReportService $service): JsonResponse
    {
        abort_unless($request->user()->can(Ability::ReportPlatformView), 403);

        return response()->json([
            'data' => $service->buildReport()->values(),
            'computed_at' => now(),
        ]);
    }
}
