<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ShareLinkType;
use App\Exceptions\MailSettingsNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Share\SendShareLinkEmailRequest;
use App\Services\Share\ShareLinkEmailService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * TASK-212 — POST /api/v1/share-emails: send a link the actor can already
 * open to an email address, through the platform SMTP row.
 *
 * Replaces <ShareLinkModal>'s `mailto:` handoff (human, 2026-08-19: "ระบบ
 * อีเมล์ให้ส่งผ่านระบบ"). `mailto:` never worked the way the button
 * implied on the surface this app actually runs on: on a phone it opens
 * whatever mail client is installed — or nothing at all, silently, if none
 * is — and the message leaves from the agent's personal address with no
 * record on the platform.
 *
 * Both failure modes are translated into a 422 with a Thai message rather
 * than a 500, because both are things the person in front of the modal can
 * act on: "the platform admin has not turned email on yet" and "the SMTP
 * server refused this". Same two-catch shape as
 * PlatformMailSettingController::test().
 */
class ShareLinkEmailController extends Controller
{
    public function store(SendShareLinkEmailRequest $request, ShareLinkEmailService $service): JsonResponse
    {
        $type = ShareLinkType::from($request->validated('type'));

        try {
            $sentTo = $service->send(
                $request->user(),
                $type,
                (int) $request->validated('id'),
                $request->validated('email'),
            );
        } catch (MailSettingsNotConfiguredException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (TransportExceptionInterface $e) {
            // Connection/auth diagnostics only — the SMTP password is never
            // part of a Symfony TransportException (see
            // PlatformMailSettingController's docblock for that check).
            return response()->json(['message' => 'ส่งอีเมลไม่สำเร็จ: '.$e->getMessage()], 422);
        }

        return response()->json([
            'message' => "ส่งอีเมลไปที่ {$sentTo} แล้ว",
            'data' => ['sent_to' => $sentTo],
        ]);
    }
}
