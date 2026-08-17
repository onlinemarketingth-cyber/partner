<?php

namespace App\Services\Platform;

use App\Exceptions\MailSettingsNotConfiguredException;
use App\Mail\SmtpTestMail;
use App\Models\AuditLog;
use App\Models\PlatformMailSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * TASK-190 §3.3 — the ONE place the platform-wide SMTP settings row (see
 * PlatformMailSetting's own docblock for why it has no company_id) is read
 * or written. Gated at the Controller/Form-Request layer by
 * Ability::SettingsMailUpdate (Super Admin only) — this Service assumes
 * that check already happened, same layering as every other Settings*
 * Service in this codebase.
 */
class PlatformMailSettingService
{
    public const CACHE_KEY = 'platform_mail_settings.row';

    /**
     * §3.3 — "returns masked: password never returned in plain". Unlike
     * the bank-account "•••• + last 4 digits" mask
     * (User::maskBankAccountNumber()), an SMTP password has no legitimate
     * reason to ever be read back through this API at all — nobody needs
     * to see a fragment of it to confirm which one is configured, only
     * whether one is set (so the settings screen knows to render the
     * password field as "already configured" vs. empty). `password_set`
     * is that boolean; the real value never leaves this Service.
     *
     * @return array{smtp_host: ?string, smtp_port: ?int, encryption: ?string, username: ?string, password_set: bool, from_address: ?string, from_name: ?string, is_enabled: bool}
     */
    public function get(): array
    {
        $settings = $this->row();

        return [
            'smtp_host' => $settings?->smtp_host,
            'smtp_port' => $settings?->smtp_port,
            'encryption' => $settings?->encryption,
            'username' => $settings?->username,
            'password_set' => filled($settings?->password),
            'from_address' => $settings?->from_address,
            'from_name' => $settings?->from_name,
            'is_enabled' => (bool) ($settings?->is_enabled ?? false),
        ];
    }

    /**
     * §3.3 — writes an `audit_logs` row, 'platform_mail_settings.updated',
     * NEVER including the password value itself (same rule as TASK-183's
     * password-reset audit / UserService::auditableRightsFields()'s
     * "nothing sensitive is ever in here").
     *
     * `password` is OPTIONAL in $data — an admin re-saving the other
     * fields (e.g. flipping `is_enabled`) must not blank out an
     * already-set password by omitting it from the form. Same
     * "only overwrite when actually present/non-empty" shape as
     * OrderService::submitSlip()'s shipping fields (ADR-033 §2.5/D2).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(array $data, User $actor): PlatformMailSetting
    {
        $settings = PlatformMailSetting::query()->first() ?? new PlatformMailSetting;

        $oldValues = $this->auditableFields($settings);

        if (! array_key_exists('password', $data) || blank($data['password'])) {
            unset($data['password']);
        }

        $settings->fill($data);
        $settings->save();

        AuditLog::create([
            // No company_id — this is platform-level config, not a tenant
            // action (AuditLog::company_id is nullable for exactly this
            // "some actions are system/cross-company" case, per that
            // Model's own docblock).
            'company_id' => null,
            'actor_user_id' => $actor->id,
            'action' => 'platform_mail_settings.updated',
            'auditable_type' => PlatformMailSetting::class,
            'auditable_id' => $settings->id,
            'old_values' => $oldValues,
            'new_values' => $this->auditableFields($settings),
            'ip_address' => request()?->ip(),
        ]);

        Cache::forget(self::CACHE_KEY);

        return $settings;
    }

    /**
     * TASK-201 — sends a real test email through the SAVED (persisted) SMTP
     * config, synchronously, for the "ทดสอบส่งอีเมล" button. Reuses row()
     * below (same cache key as applyRuntimeConfig()/get()/update() — no
     * second cache path invented for this).
     *
     * FAIL CLOSED, same rule as MailSettingsService::applyRuntimeConfig():
     * that method never flips `mail.default` away from the `.env` `log`
     * mailer when no row exists or `is_enabled` is false, so a "successful"
     * send in that state would actually just be logged, not delivered — a
     * false positive. This method checks that BEFORE calling
     * Mail::to()->send(), and throws MailSettingsNotConfiguredException
     * without attempting anything.
     *
     * Transport exceptions are DELIBERATELY NOT caught here — they
     * propagate to the Controller (PlatformMailSettingController::test()),
     * which translates them into a 422 with the underlying message. That
     * message is safe to expose: it's connection/auth diagnostics (host
     * unreachable, auth failed), never the password itself (Symfony's
     * TransportException never includes it — see the Controller's own
     * docblock for the double-check).
     *
     * Sent via Mail::to()->send(), NOT Notification — this has no `User`
     * recipient semantics (the `to` address is admin-supplied, possibly not
     * even a User in this system) — and NOT queued (no ShouldQueue on
     * SmtpTestMail), same "admin is actively watching" reasoning as
     * OrderPaymentConfirmedMail.
     */
    public function sendTest(string $to, User $actor): void
    {
        $settings = $this->row();

        if ($settings === null || ! $settings->is_enabled) {
            throw new MailSettingsNotConfiguredException;
        }

        Mail::to($to)->send(new SmtpTestMail($settings->from_name, $settings->from_address));

        AuditLog::create([
            // No company_id — platform-level action, same as update()'s own
            // audit entry above.
            'company_id' => null,
            'actor_user_id' => $actor->id,
            'action' => 'platform_mail_settings.test_sent',
            'auditable_type' => PlatformMailSetting::class,
            'auditable_id' => $settings->id,
            'old_values' => null,
            // The `to` address is the only thing worth recording here — not
            // sensitive (Section 6 / CLAUDE.md §8 rule 5's "who/what/when",
            // never the password).
            'new_values' => ['to' => $to],
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * §3.5 — briefly cached so MailSettingsService::applyRuntimeConfig()
     * (called once per request in AppServiceProvider::boot()) does not add
     * a query to every single request. update() above forgets this key
     * immediately, so a saved change is visible on the very next request
     * rather than waiting out the TTL.
     */
    private function row(): ?PlatformMailSetting
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => PlatformMailSetting::query()->first());
    }

    /**
     * The audited snapshot. NEVER includes `password` — `password_set`
     * (bool) stands in for it, so the trail still shows WHETHER a password
     * changed without ever showing what it changed to/from (Section 6 /
     * CLAUDE.md §8 rule — secrets never logged in plain).
     *
     * @return array<string, mixed>
     */
    private function auditableFields(PlatformMailSetting $settings): array
    {
        return [
            'smtp_host' => $settings->smtp_host,
            'smtp_port' => $settings->smtp_port,
            'encryption' => $settings->encryption,
            'username' => $settings->username,
            'password_set' => filled($settings->password),
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'is_enabled' => (bool) $settings->is_enabled,
        ];
    }
}
