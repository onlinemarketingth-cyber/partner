<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\UpdateAgentRankSettingRequest;
use App\Http\Resources\AgentRankSettingResource;
use App\Services\Commission\AgentRankSettingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// ADR-011/TASK-031 — same shape as CommissionBinarySettingController/
// CommissionMatrixSettingController.
class AgentRankSettingController extends Controller
{
    public function show(Request $request, AgentRankSettingService $service): AgentRankSettingResource|Response
    {
        abort_unless($request->user()->can(Ability::SettingsAgentRankView), 403);

        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id') ?: null
            : $request->user()->company_id;

        $setting = $companyId ? $service->forCompany($companyId) : null;

        return $setting ? new AgentRankSettingResource($setting) : response()->noContent();
    }

    public function update(UpdateAgentRankSettingRequest $request, AgentRankSettingService $service): AgentRankSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $setting = $service->upsert($companyId, $request->validated());

        return new AgentRankSettingResource($setting);
    }
}
