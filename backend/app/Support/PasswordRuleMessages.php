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

            // The three character-class rules, each naming the ONE thing
            // that is missing. Laravel raises them together, so somebody who
            // typed "12345678" is told about the letters AND the mixed case
            // at once and can fix both in one go — which is exactly why
            // these were chosen over a breach check.
            //
            // The key names are not a guess:
            // Illuminate\Validation\Rules\Password::validate() calls
            // addFailure($attribute, 'password.mixed' | 'password.letters' |
            // 'password.numbers' | 'password.symbols'), and the inner
            // Validator it builds is handed $this->validator->customMessages
            // — which is how an override written here reaches it at all.
            //
            // No 'password.symbols' entry: symbols() is deliberately not in
            // the policy (AppServiceProvider says why). Adding the message
            // without the rule would read as if it were.
            'password.mixed' => 'รหัสผ่านต้องมีตัวอักษรพิมพ์ใหญ่และพิมพ์เล็กอย่างน้อยอย่างละ 1 ตัว',
            'password.letters' => 'รหัสผ่านต้องมีตัวอักษรภาษาอังกฤษอย่างน้อย 1 ตัว',
            'password.numbers' => 'รหัสผ่านต้องมีตัวเลขอย่างน้อย 1 ตัว',

            // uncompromised() is NO LONGER in the policy — the owner chose
            // character classes over the breach check on 2026-08-21, and the
            // trade is recorded in AppServiceProvider rather than here. This
            // line stays so that turning the rule back on is one edit there
            // instead of two in two files, and so it can never reappear in
            // English if someone does.
            'password.uncompromised' => 'รหัสผ่านนี้เคยปรากฏในเหตุข้อมูลรั่วไหลของเว็บอื่น กรุณาตั้งรหัสผ่านอื่นที่ไม่เคยใช้ที่ไหนมาก่อน',
        ];
    }
}
