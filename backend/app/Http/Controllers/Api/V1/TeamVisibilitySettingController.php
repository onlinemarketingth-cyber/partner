<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\UpdateTeamVisibilitySettingRequest;
use App\Http\Resources\TeamVisibilitySettingResource;
use App\Services\Sales\TeamVisibilitySettingService;
use Illuminate\Http\Request;

// TASK-106 / ADR-024 §5 — Company Admin (own company) / Super Admin only,
// same authorization shape as VideoProcessingSettingController.
//
// Unlike announcement-settings, show() is NOT Agent-readable: this config
// is the PDPA boundary a leader is subject to, and the Agent Portal never
// needs to read it directly — TASK-107's /me/team responses already carry
// the resolved level for the caller.
class TeamVisibilitySettingController extends Controller
{
    public function show(Request $request, TeamVisibilitySettingService $service): TeamVisibilitySettingResource
    {
        abort_unless($request->user()->can(Ability::SettingsTeamVisibilityView), 403);

        // BR-6 — ?company_id is honoured for a Super Admin only. For anyone
        // else the parameter is ignored outright rather than validated and
        // rejected: there is no code path where a Company Admin's request
        // can be pointed at another tenant's row.
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id') ?: null
            : $request->user()->company_id;

        return new TeamVisibilitySettingResource($service->forCompany($companyId));
    }

    public function update(UpdateTeamVisibilitySettingRequest $request, TeamVisibilitySettingService $service): TeamVisibilitySettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $service->upsert($companyId, $request->validated());

        return new TeamVisibilitySettingResource($service->forCompany($companyId));
    }
}
