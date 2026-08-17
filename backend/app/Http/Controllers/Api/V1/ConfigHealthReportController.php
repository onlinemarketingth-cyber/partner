<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Services\Platform\ConfigHealthReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// TASK-041 (4.4) — Policy & Report IA item 4, BR-7 config health
// tracker. Company Admin (own company only) or Super Admin (all
// companies, optionally narrowed via ?company_id=) only — NOT Agent.
class ConfigHealthReportController extends Controller
{
    public function index(Request $request, ConfigHealthReportService $service): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->can(Ability::ReportConfigHealthView), 403);

        $companyId = $user->isSuperAdmin() && $request->filled('company_id')
            ? $request->integer('company_id')
            : null;

        return response()->json([
            'data' => $service->buildReport($user, $companyId)->values(),
            'computed_at' => now(),
        ]);
    }
}
