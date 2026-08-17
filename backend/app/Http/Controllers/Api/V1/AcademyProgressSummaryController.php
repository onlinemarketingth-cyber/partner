<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\AcademyProgressSummaryRequest;
use App\Services\Academy\AcademyProgressSummaryService;
use Illuminate\Http\JsonResponse;

/**
 * TASK-152 — GET /api/v1/academy-progress-summary.
 *
 * The server-side replacement for the ความคืบหน้าตัวแทน tab's client-side join
 * of three separately paginated endpoints. See
 * AcademyProgressSummaryService's docblock for the bug and the fix.
 *
 * Returns a curated aggregate payload (`data` / `meta` / `summary` /
 * `computed_at`), not Eloquent models, so it follows the same shape as
 * AgentCommissionSummaryController, PlatformReportController and
 * ConfigHealthReportController rather than wrapping arrays in an API Resource.
 * CLAUDE.md §7's "API Resources on every JSON response" exists to stop raw
 * models leaking unintended fields; there is no model here to leak — every key
 * is written out explicitly in the Service.
 *
 * Authorization and company scoping live entirely in
 * AcademyProgressSummaryRequest (Policy check + BR-6 company_id guard), so this
 * class stays thin as §7 requires.
 */
class AcademyProgressSummaryController extends Controller
{
    public function index(AcademyProgressSummaryRequest $request, AcademyProgressSummaryService $service): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $result = $service->build(
            companyId: $request->effectiveCompanyId(),
            search: $search === '' ? null : $search,
            perPage: (int) $request->integer('per_page', 25),
        );

        return response()->json($result + ['computed_at' => now()]);
    }
}
