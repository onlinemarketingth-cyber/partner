<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\UpdateCommissionGenerationSettingRequest;
use App\Http\Resources\CommissionGenerationSettingResource;
use App\Services\Commission\CommissionGenerationSettingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// ADR-011/TASK-031 — same shape as CommissionMatrixSettingController.
class CommissionGenerationSettingController extends Controller
{
    public function show(Request $request, CommissionGenerationSettingService $service): CommissionGenerationSettingResource|Response
    {
        abort_unless($request->user()->can(Ability::SettingsCommissionGenerationView), 403);

        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id') ?: null
            : $request->user()->company_id;

        $setting = $companyId ? $service->forCompany($companyId) : null;

        return $setting ? new CommissionGenerationSettingResource($setting) : response()->noContent();
    }

    public function update(UpdateCommissionGenerationSettingRequest $request, CommissionGenerationSettingService $service): CommissionGenerationSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $setting = $service->upsert($companyId, $request->validated());

        return new CommissionGenerationSettingResource($setting);
    }
}
