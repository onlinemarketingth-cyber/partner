<?php

namespace App\Exceptions;

use Exception;

/**
 * TASK-201 — thrown by PlatformMailSettingService::sendTest() when the
 * saved `platform_mail_settings` row is missing or `is_enabled` is false.
 *
 * Mirrors MailSettingsService::applyRuntimeConfig()'s own fail-closed rule
 * (TASK-190 §3.5): in that state `mail.default` is never flipped away from
 * the `.env` `log` mailer, so attempting a "test send" while disabled would
 * silently succeed against the log channel — a false positive that defeats
 * the entire point of this feature. sendTest() checks this BEFORE calling
 * Mail::to()->send(), and PlatformMailSettingController::test() catches
 * this exception explicitly (rather than relying on default exception
 * rendering) so it sits alongside the transport-exception catch as one
 * unambiguous 422 shape for the frontend.
 */
class MailSettingsNotConfiguredException extends Exception
{
    public function __construct()
    {
        parent::__construct('กรุณาเปิดใช้งานและบันทึกการตั้งค่า SMTP ก่อนทดสอบส่งอีเมล');
    }
}
