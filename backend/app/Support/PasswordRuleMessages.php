<?php

namespace App\Support;

/**
 * Thai messages for the shared password policy.
 *
 * ── WHY THIS EXISTS ──
 *
 * The 2026-08-21 audit put the password POLICY in one place
 * (Password::defaults() in AppServiceProvider) so four Form Requests could
 * not drift apart. It left the MESSAGES scattered — which is the same
 * problem wearing a different hat, and it showed up immediately in
 * production: a Thai-language app told an agent
 *
 *     "The password field must be at least 10 characters."
 *
 * in English, directly underneath a Thai hint that said 8. Two different
 * numbers, two different languages, one field.
 *
 * ── WHY NOT lang/th/validation.php ──
 *
 * Because APP_LOCALE is `en` and APP_FALLBACK_LOCALE is `en`, so a Thai
 * translation file would never be loaded. The app's actual convention is
 * Thai strings written directly in each Form Request's messages() — see
 * UpdatePasswordRequest and RegisterRequest, which already do this. This
 * follows that convention and gives it one home.
 *
 * ── THE KEY NAMES ARE NOT A GUESS ──
 *
 * `password.min` was verified against the real validator: the Password
 * rule reports a short value through `<attribute>.min`, NOT through
 * `min.string`, which is what an override would reach for and which
 * silently does nothing. Getting this wrong looks exactly like working
 * code — the English message simply stays.
 */
final class PasswordRuleMessages
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            // Keep the number in step with Password::defaults() in
            // AppServiceProvider, and with the hint under the field in
            // ProfileSettingsView.vue. Three places say it; they must agree.
            'password.min' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร',

            // uncompromised() checks the password against known breach data
            // (only a 5-character hash prefix leaves the server). Saying so
            // matters: "this password is not allowed" with no reason reads
            // as an arbitrary rule, and people retry variations of the same
            // leaked password rather than choosing a different one.
            'password.uncompromised' => 'รหัสผ่านนี้เคยปรากฏในเหตุข้อมูลรั่วไหลของเว็บอื่น กรุณาตั้งรหัสผ่านอื่นที่ไม่เคยใช้ที่ไหนมาก่อน',
        ];
    }
}
