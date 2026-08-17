<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\UpdateVideoProcessingSettingRequest;
use App\Http\Resources\VideoProcessingSettingResource;
use App\Services\Catalog\VideoProcessingSettingService;
use Illuminate\Http\Request;

// ADR-007 — Company Admin (own company)/Super Admin only, same
// authorization shape as UpdateVideoProcessingSettingRequest.
class VideoProcessingSettingController extends Controller
{
    public function show(Request $request, VideoProcessingSettingService $service): VideoProcessingSettingResource
    {
        abort_unless($request->user()->can(Ability::SettingsVideoProcessingView), 403);

        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id') ?: null
            : $request->user()->company_id;

        return new VideoProcessingSettingResource($service->forCompany($companyId));
    }

    public function update(UpdateVideoProcessingSettingRequest $request, VideoProcessingSettingService $service): VideoProcessingSettingResource
    {
        $companyId = $request->user()->isSuperAdmin()
            ? $request->integer('company_id')
            : $request->user()->company_id;

        $service->upsert($companyId, $request->validated());

        return new VideoProcessingSettingResource($service->forCompany($companyId));
    }
}
