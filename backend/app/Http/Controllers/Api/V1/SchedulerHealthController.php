<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\SchedulerHeartbeatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 2026-10-02 — GET /platform/scheduler-health. Super Admin only: the
 * scheduler is the platform's, not any one company's, and a Company Admin
 * can do nothing about a cron entry on the host.
 *
 * Returns only timestamps and a flag — no job names, output or paths.
 */
class SchedulerHealthController extends Controller
{
    public function show(Request $request, SchedulerHeartbeatService $heartbeat): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return response()
            ->json(['data' => $heartbeat->status()])
            ->header('Cache-Control', 'no-store, private');
    }
}
