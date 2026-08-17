<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\UpdateAnnouncementSettingRequest;
use App\Http\Resources\AnnouncementSettingResource;
use App\Services\Engagement\AnnouncementSettingService;
use Illuminate\Http\Request;

// TASK-076 — same shape as VideoProcessingSettingController/
// AffiliateAttributionSettingController.
class AnnouncementSettingController extends Controller
{
    /**
     * show() is readable by ANY authenticated role (Agent included) —
     * the Agent Portal Home screen needs `repeat_count` to know how many
     * times to auto-pop an announcement before it stops (BR-7: the
     * value itself stays admin-editable, only *reading* it is widened
     * here — same precedent as AffiliateAttributionSettingController).
     * update() stays Company Admin/Super Admin only, enforced in
     * UpdateAnnouncementSettingRequest::authorize().
     *
     * An Agent/Company Admin is always forced to their own company_id
     * (the ?company_id= override stays Super-Admin-only).
     */
    public function show(Request $request, AnnouncementSettingService $service): AnnouncementSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? ($request->integer('company_id') ?: null)
            : $request->user()->company_id;

        return new AnnouncementSettingResource($service->forCompany($companyId));
    }

    public function update(UpdateAnnouncementSettingRequest $request, AnnouncementSettingService $service): AnnouncementSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $service->upsert($companyId, $request->validated());

        return new AnnouncementSettingResource($service->forCompany($companyId));
    }
}
