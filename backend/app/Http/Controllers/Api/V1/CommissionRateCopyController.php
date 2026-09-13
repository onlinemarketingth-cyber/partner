<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\CopyCommissionRatesRequest;
use App\Services\Commission\CommissionRateCopyService;
use Illuminate\Http\JsonResponse;

/**
 * 2026-09-13 — POST /commission-rules/copy.
 *
 * One endpoint, two modes, because a preview computed by different code from
 * the write is a preview that can lie about money. See
 * CommissionRateCopyService for the whole reasoning, including why the system
 * copies on request rather than seeding a default nobody chose.
 */
class CommissionRateCopyController extends Controller
{
    public function __invoke(CopyCommissionRatesRequest $request, CommissionRateCopyService $service): JsonResponse
    {
        $from = $request->integer('from_company_id');
        $to = $request->integer('to_company_id');

        // boolean('dry_run') reads a MISSING key as false, which is the wrong
        // default for a write — see the Form Request's note. Asked as
        // "explicitly told not to" instead.
        $isDryRun = ! $request->has('dry_run') || $request->boolean('dry_run');

        $payload = $isDryRun
            ? $service->preview($from, $to)
            : $service->apply($from, $to, $request->user());

        return response()->json(['data' => $payload + ['dry_run' => $isDryRun]]);
    }
}
