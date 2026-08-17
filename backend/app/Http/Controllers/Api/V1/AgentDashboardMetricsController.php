<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Services\Sales\AgentDashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// TASK-052 / ADR-015 — chart-based Agent Dashboard metrics. Company Admin
// (own company) / Super Admin only, same role-gate shape as
// SalesTeamOverviewController.
class AgentDashboardMetricsController extends Controller
{
    public function index(Request $request, AgentDashboardMetricsService $service): JsonResponse
    {
        abort_unless($request->user()->can(Ability::SalesAgentDashboardMetricsView), 403);

        $companyId = $request->user()->isSuperAdmin()
            ? ($request->integer('company_id') ?: null)
            : $request->user()->company_id;

        return response()->json(['data' => $service->build($request->user(), $companyId)]);
    }
}
