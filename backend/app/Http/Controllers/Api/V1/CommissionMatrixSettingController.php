<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\UpdateCommissionMatrixSettingRequest;
use App\Http\Resources\CommissionMatrixSettingResource;
use App\Services\Commission\CommissionMatrixSettingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// ADR-011/TASK-030 — same shape as CommissionBinarySettingController.
class CommissionMatrixSettingController extends Controller
{
    public function show(Request $request, CommissionMatrixSettingService $service): CommissionMatrixSettingResource|Response
    {
        abort_unless($request->user()->can(Ability::SettingsCommissionMatrixView), 403);

        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id') ?: null
            : $request->user()->company_id;

        $setting = $companyId ? $service->forCompany($companyId) : null;

        return $setting ? new CommissionMatrixSettingResource($setting) : response()->noContent();
    }

    public function update(UpdateCommissionMatrixSettingRequest $request, CommissionMatrixSettingService $service): CommissionMatrixSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $setting = $service->upsert($companyId, $request->validated());

        return new CommissionMatrixSettingResource($setting);
    }
}
