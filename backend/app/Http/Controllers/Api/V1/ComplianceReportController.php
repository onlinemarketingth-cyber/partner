<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Services\Compliance\ComplianceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// TASK-041 (4.3) — Policy & Report IA item 4, PDPA/Compliance report.
// Company Admin or Super Admin only — NOT Agent (PDPA-sensitive
// aggregate over client consent state, Section 6).
class ComplianceReportController extends Controller
{
    public function index(Request $request, ComplianceReportService $service): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->can(Ability::ReportComplianceView), 403);

        return response()->json([
            'data' => $service->buildReport($user),
            'computed_at' => now(),
        ]);
    }
}
