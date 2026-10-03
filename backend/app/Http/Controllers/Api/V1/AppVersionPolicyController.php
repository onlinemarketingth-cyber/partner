<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DevicePlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateAppVersionPolicyRequest;
use App\Http\Requests\Public\ShowAppVersionPolicyRequest;
use App\Http\Resources\AppVersionPolicyResource;
use App\Services\Platform\AppVersionPolicyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 2026-10-02 — MOB-13. The mobile app version policy.
 *
 *   GET /app/version-policy?platform=…    public, throttled — the app's
 *                                         launch check (see the Request)
 *   GET /platform/app-version-policies    Super Admin — both platforms
 *   PUT /platform/app-version-policies    Super Admin — one platform
 */
class AppVersionPolicyController extends Controller
{
    public function show(ShowAppVersionPolicyRequest $request, AppVersionPolicyService $service): AppVersionPolicyResource
    {
        return new AppVersionPolicyResource(
            $service->forPlatform(DevicePlatform::from($request->validated('platform'))),
        );
    }

    public function index(Request $request, AppVersionPolicyService $service): AnonymousResourceCollection
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return AppVersionPolicyResource::collection($service->all());
    }

    public function update(UpdateAppVersionPolicyRequest $request, AppVersionPolicyService $service): AppVersionPolicyResource
    {
        $policy = $service->update(
            DevicePlatform::from($request->validated('platform')),
            $request->validated(),
            $request->user(),
        );

        return new AppVersionPolicyResource($policy);
    }
}
