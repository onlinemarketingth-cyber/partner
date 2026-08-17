<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\UpdateAcademyCompletionSettingRequest;
use App\Http\Resources\AcademyCompletionSettingResource;
use App\Services\Academy\AcademyCompletionSettingService;
use Illuminate\Http\Request;

// ADR-028 §4 / BR-7 — Company Admin (own company) / Super Admin only,
// same authorization and singleton shape as
// VideoProcessingSettingController.
//
// Deliberately NOT Agent-readable: the thresholds ARE the numbers ADR-028
// §4 decided a learner should not see. Exposing them here would leak
// through the back door what the completion endpoint carefully withholds.
class AcademyCompletionSettingController extends Controller
{
    public function show(Request $request, AcademyCompletionSettingService $service): AcademyCompletionSettingResource
    {
        abort_unless($request->user()->can(Ability::SettingsAcademyCompletionView), 403);

        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id') ?: null
            : $request->user()->company_id;

        return new AcademyCompletionSettingResource($service->forCompany($companyId));
    }

    public function update(UpdateAcademyCompletionSettingRequest $request, AcademyCompletionSettingService $service): AcademyCompletionSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $service->upsert($companyId, $request->validated());

        return new AcademyCompletionSettingResource($service->forCompany($companyId));
    }
}
