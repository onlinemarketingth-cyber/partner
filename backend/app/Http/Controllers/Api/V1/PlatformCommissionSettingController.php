<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdatePlatformCommissionSettingRequest;
use App\Services\Platform\PlatformCommissionSettingService;
use Illuminate\Http\JsonResponse;

// TASK-196 §2.2 — GET/PUT /api/v1/platform/commission-cap.
//
// show() has NO ability check beyond the surrounding auth:sanctum
// middleware group — §2.2 calls for "read-everywhere" (any authenticated
// Company Admin/Super Admin who can reach the 3 commission-rule forms),
// same shape as CertTierController's own "must be authenticated, nothing
// more" read gate (see that Controller's own docblock for the identical
// reasoning: every role needs the cap to render the live client-side
// check, so gating it further would just break the UI for whoever it's
// gated against).
//
// update() IS gated, via UpdatePlatformCommissionSettingRequest::
// authorize() (Ability::CommissionRateCapUpdate, Super Admin only) — same
// belt-and-suspenders split as PlatformMailSettingController.
class PlatformCommissionSettingController extends Controller
{
    public function show(PlatformCommissionSettingService $service): JsonResponse
    {
        return response()->json(['data' => $service->get()]);
    }

    public function update(UpdatePlatformCommissionSettingRequest $request, PlatformCommissionSettingService $service): JsonResponse
    {
        $service->update($request->validated(), $request->user());

        return response()->json(['data' => $service->get()]);
    }
}
