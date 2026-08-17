<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Ability;
use App\Exceptions\MailSettingsNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SendTestMailRequest;
use App\Http\Requests\Platform\UpdatePlatformMailSettingRequest;
use App\Services\Platform\PlatformMailSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

// TASK-190 §3.4 — GET/PUT /api/v1/platform/mail-settings, both gated by
// Ability::SettingsMailUpdate (Super Admin only — see that case's
// docblock). Returns a curated masked array, not an API Resource over the
// raw Model — same "payload isn't a raw Eloquent model" shape
// AgentCommissionSummaryController/PlatformReportController use, and here
// it is load-bearing rather than incidental: PlatformMailSettingService::
// get() is THE thing standing between the encrypted password column and
// the HTTP response, so a Resource wrapping the Model directly would be
// exactly the field-leakage Section 7's Resource rule exists to prevent,
// not a use of it.
class PlatformMailSettingController extends Controller
{
    public function show(Request $request, PlatformMailSettingService $service): JsonResponse
    {
        abort_unless($request->user()->can(Ability::SettingsMailUpdate), 403);

        return response()->json(['data' => $service->get()]);
    }

    public function update(UpdatePlatformMailSettingRequest $request, PlatformMailSettingService $service): JsonResponse
    {
        $service->update($request->validated(), $request->user());

        return response()->json(['data' => $service->get()]);
    }

    /**
     * TASK-201 — POST /api/v1/platform/mail-settings/test, the "ทดสอบส่งอีเมล"
     * button. Both failure modes PlatformMailSettingService::sendTest() can
     * produce are caught explicitly here (rather than left to default
     * exception rendering) so the frontend always gets the same
     * `{"message": "..."}` / 422 shape to key off of, per the task spec:
     *
     * - MailSettingsNotConfiguredException: the saved row is missing or
     *   disabled — sendTest() never attempted a send in this case.
     * - TransportExceptionInterface: a real SMTP connection/auth failure.
     *   Exposing $e->getMessage() here is intentional and safe — Symfony's
     *   transport exceptions carry connection/protocol diagnostics (host
     *   unreachable, auth rejected) and never embed the password itself
     *   (it is used to compute an auth handshake, not echoed into any
     *   exception message the transport layer raises).
     */
    public function test(SendTestMailRequest $request, PlatformMailSettingService $service): JsonResponse
    {
        try {
            $service->sendTest($request->validated('to'), $request->user());
        } catch (MailSettingsNotConfiguredException|TransportExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'ส่งอีเมลทดสอบสำเร็จ']);
    }
}
