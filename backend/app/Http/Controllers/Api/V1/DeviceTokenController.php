<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DevicePlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\RegisterDeviceTokenRequest;
use App\Http\Requests\Profile\UnregisterDeviceTokenRequest;
use App\Http\Resources\DeviceTokenResource;
use App\Services\Notification\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * 2026-10-02 — MOB-10. POST / DELETE /api/v1/me/devices.
 *
 * Self-scoped by construction, like every /me/* route: there is no {id} in
 * either URL, the token travels in the body, and the caller is always
 * $request->user(). See DeviceTokenService for the one deliberate tenant
 * crossing (a phone changing hands) and why it reveals nothing.
 */
class DeviceTokenController extends Controller
{
    public function store(RegisterDeviceTokenRequest $request, DeviceTokenService $service): JsonResponse
    {
        $device = $service->register(
            $request->user(),
            DevicePlatform::from($request->validated('platform')),
            $request->validated('token'),
            $request->validated('app_version'),
        );

        return (new DeviceTokenResource($device))->response()->setStatusCode(201);
    }

    /**
     * Always 204, found or not: a 404 for "not yours" would tell a caller
     * that the token they sent belongs to somebody.
     */
    public function destroy(UnregisterDeviceTokenRequest $request, DeviceTokenService $service): Response
    {
        $service->unregister($request->user(), $request->validated('token'));

        return response()->noContent();
    }
}
