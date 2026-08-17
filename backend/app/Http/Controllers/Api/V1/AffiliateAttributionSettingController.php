<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Referral\UpdateAffiliateAttributionSettingRequest;
use App\Http\Resources\AffiliateAttributionSettingResource;
use App\Services\Referral\AffiliateAttributionSettingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// ADR-011/TASK-032 — same shape as AgentRankSettingController/CommissionMatrixSettingController.
class AffiliateAttributionSettingController extends Controller
{
    /**
     * TASK-033 gap-fill: show() is now readable by ANY authenticated
     * role (Agent included), not just Company Admin/Super Admin — the
     * "My Affiliate Links" screen displays this window read-only so an
     * Agent understands why an old click stopped counting as a
     * conversion (BR-7: the value itself stays admin-editable, only
     * *reading* it is being widened here). update() is unchanged —
     * still Company Admin/Super Admin only, enforced below.
     *
     * An Agent is always forced to their own company_id (the
     * ?company_id= override stays Super-Admin-only) — same narrowing
     * pattern as AffiliateLinkController::index().
     */
    public function show(Request $request, AffiliateAttributionSettingService $service): AffiliateAttributionSettingResource|Response
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id') ?: null
            : $request->user()->company_id;

        $setting = $companyId ? $service->forCompany($companyId) : null;

        return $setting ? new AffiliateAttributionSettingResource($setting) : response()->noContent();
    }

    public function update(UpdateAffiliateAttributionSettingRequest $request, AffiliateAttributionSettingService $service): AffiliateAttributionSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $setting = $service->upsert($companyId, $request->validated());

        return new AffiliateAttributionSettingResource($setting);
    }
}
