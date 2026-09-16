<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Services\Sales\BusinessOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /business-overview — ภาพรวมธุรกิจ. 2026-09-16.
 *
 * ONE endpoint for the whole screen, on purpose. Every figure on it is
 * filtered to the same window on the same date column, and stitching the page
 * together from the five existing report endpoints would have reintroduced
 * precisely the problem it exists to fix: four of them bucket on four
 * different dates, so "September" meant four things.
 *
 * Same gate as the agent dashboard beside it — Company Admin over their own
 * company, Super Admin over any or all. A null company for a Super Admin is
 * the deliberate read-across, which is why it is derived here rather than
 * taken from the client for everybody.
 */
class BusinessOverviewController extends Controller
{
    public function index(Request $request, BusinessOverviewService $service): JsonResponse
    {
        abort_unless($request->user()->can(Ability::SalesAgentDashboardMetricsView), 403);

        $validated = $request->validate([
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ]);

        /*
         * Default: the current month.
         *
         * The window is REQUIRED to exist — every number on this screen is
         * meaningless without one, and a default of "all time" would make the
         * first paint a figure nobody asked for and few would recognise. The
         * month is what an owner checking the business is usually looking at,
         * and the screen offers quarter and year one click away.
         */
        $from = isset($validated['date_from'])
            ? Carbon::parse($validated['date_from'])
            : Carbon::now()->startOfMonth();

        $to = isset($validated['date_to'])
            ? Carbon::parse($validated['date_to'])
            : Carbon::now();

        $companyId = $request->user()->isSuperAdmin()
            ? ($request->integer('company_id') ?: null)
            : $request->user()->company_id;

        return response()->json(['data' => $service->build($companyId, $from, $to)]);
    }
}
