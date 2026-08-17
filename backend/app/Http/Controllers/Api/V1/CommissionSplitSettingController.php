<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\UpdateCommissionSplitSettingRequest;
use App\Http\Resources\CommissionSplitSettingResource;
use App\Services\Commission\CommissionSplitSettingService;
use Illuminate\Http\Request;

/**
 * TASK-174 (D2) — the per-company switch for TASK-026's co-agent commission
 * split. Same shape as AnnouncementSettingController /
 * TeamVisibilitySettingController.
 *
 * show() is readable by ANY authenticated role, Agent included: the Agent
 * Portal needs the flag to decide whether to render the split controls at
 * all (ag-ui, spec §8 step 2). That is a widening of READ only — update()
 * stays Company Admin/Super Admin, enforced in
 * UpdateCommissionSplitSettingRequest::authorize(). Same precedent as
 * AffiliateAttributionSettingController.
 *
 * An Agent/Company Admin is always forced to their own company_id; the
 * ?company_id= override is Super-Admin-only (BR-6).
 */
class CommissionSplitSettingController extends Controller
{
    public function show(Request $request, CommissionSplitSettingService $service): CommissionSplitSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? ($request->integer('company_id') ?: null)
            : $request->user()->company_id;

        return new CommissionSplitSettingResource($this->payload($request, $service, $companyId));
    }

    public function update(UpdateCommissionSplitSettingRequest $request, CommissionSplitSettingService $service): CommissionSplitSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $service->upsert($companyId, $request->validated(), $request->user());

        return new CommissionSplitSettingResource($this->payload($request, $service, $companyId));
    }

    /**
     * Spec §6 — an admin flipping this ON must SEE how many pending
     * referrals still carry a stored split, because those resume splitting
     * the moment the switch flips, with nobody touching the deals. The count
     * rides along on every response rather than living behind a second
     * endpoint, so the number is never one request out of date with the flag
     * it describes.
     *
     * @return array{is_enabled: bool, pending_referrals_with_stored_split?: int}
     */
    private function payload(Request $request, CommissionSplitSettingService $service, ?int $companyId): array
    {
        $payload = $service->forCompany($companyId);

        if ($companyId !== null && ($request->user()->isSuperAdmin() || $request->user()->isCompanyAdmin())) {
            $payload['pending_referrals_with_stored_split'] = $service->pendingReferralsWithStoredSplitCount($companyId);
        }

        return $payload;
    }
}
