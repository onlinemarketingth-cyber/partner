<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Services\Sales\SalesTeamOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// TASK-050 / ADR-014 — "ทีมขาย" leadership cockpit. Company Admin (own
// company) / Super Admin only; an Agent has no company-wide team view
// (they use the Agent Portal's own-clients/own-pipeline screens). Same
// role-gate shape as VideoProcessingSettingController / the other
// Admin-only read endpoints.
class SalesTeamOverviewController extends Controller
{
    public function index(Request $request, SalesTeamOverviewService $service): JsonResponse
    {
        abort_unless($request->user()->can(Ability::SalesTeamOverviewView), 403);

        $companyId = $request->user()->isSuperAdmin()
            ? ($request->integer('company_id') ?: null)
            : $request->user()->company_id;

        // TASK-179 §3.6 (F-15) — `data` is unchanged (the per-agent rows).
        // `meta.clients_total` is ADDITIVE: a true company-level
        // COUNT(DISTINCT client_id), which the header KPI must use instead
        // of summing data[].client_count — that sum double-counts a client
        // referred by two agents.
        $result = $service->buildWithTotals($request->user(), $companyId);

        return response()->json([
            'data' => $result['agents'],
            'meta' => ['clients_total' => $result['clients_total']],
        ]);
    }
}
