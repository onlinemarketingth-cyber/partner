<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commission\UpdateCommissionBinarySettingRequest;
use App\Http\Resources\CommissionBinarySettingResource;
use App\Services\Commission\CommissionBinarySettingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// ADR-011/TASK-029 — Company Admin (own company)/Super Admin only, same
// authorization shape as VideoProcessingSettingController. Replaces the
// "อยู่ระหว่างพัฒนา" placeholder now that BinaryCommissionService
// actually reads this config (see that Service's runDueCycles()
// docblock — a company with no row here is simply never processed).
class CommissionBinarySettingController extends Controller
{
    public function show(Request $request, CommissionBinarySettingService $service): CommissionBinarySettingResource|Response
    {
        abort_unless($request->user()->can(Ability::SettingsCommissionBinaryView), 403);

        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id') ?: null
            : $request->user()->company_id;

        $setting = $companyId ? $service->forCompany($companyId) : null;

        // 204, not a resource wrapping null — unlike VideoProcessingSetting
        // (which always has a platform-default fallback), "not configured
        // yet" is a real, distinct state here that ag-ui needs to tell
        // apart from "configured with a zero rate".
        return $setting ? new CommissionBinarySettingResource($setting) : response()->noContent();
    }

    public function update(UpdateCommissionBinarySettingRequest $request, CommissionBinarySettingService $service): CommissionBinarySettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $setting = $service->upsert($companyId, $request->validated());

        return new CommissionBinarySettingResource($setting);
    }
}
