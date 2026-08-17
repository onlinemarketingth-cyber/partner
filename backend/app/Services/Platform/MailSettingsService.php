<?php

namespace App\Services\Platform;

use App\Models\PlatformMailSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * TASK-190 §3.5 — wires the `platform_mail_settings` row into Laravel's
 * ACTUAL mail sending. Called once per request from
 * AppServiceProvider::boot() (§3.5's "simplest correct integration
 * point" — every mail send in the same request, whichever Mailable it is,
 * then reads whatever config() already holds, exactly like every other
 * Laravel mail call site in the framework).
 *
 * FAIL CLOSED (ADR-032 §2.5's rule extended to mail, per spec §3.1): when
 * no row exists or `is_enabled` is false, this method does NOTHING — the
 * `.env` MAIL_MAILER=log default is left completely untouched, so an
 * unconfigured platform never silently starts attempting real SMTP calls.
 */
class MailSettingsService
{
    public function applyRuntimeConfig(): void
    {
        // GUARD (not in the spec, added because boot() runs on EVERY
        // artisan invocation, not just HTTP requests): the very first
        // `php artisan migrate` on a fresh clone/CI box boots this same
        // provider chain BEFORE this migration has created the table, and
        // `Cache::remember()`'s callback would otherwise throw a "table
        // doesn't exist" QueryException and fail the migrate command
        // itself. Schema::hasTable() is cheap (single SHOW TABLES-style
        // check) and this whole feature is meaningless before the table
        // exists anyway, so skipping is the same "do nothing" fail-closed
        // behavior as an empty/disabled row.
        try {
            if (! Schema::hasTable('platform_mail_settings')) {
                return;
            }

            $settings = Cache::remember(
                PlatformMailSettingService::CACHE_KEY,
                60,
                fn () => PlatformMailSetting::query()->first(),
            );
        } catch (Throwable) {
            // Same reasoning, belt-and-suspenders: a DB connection that
            // isn't up yet (e.g. very early in some artisan commands) must
            // never turn "read a config row" into a fatal boot error.
            return;
        }

        if ($settings === null || ! $settings->is_enabled) {
            return;
        }

        config([
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => null,
                'url' => null,
                'host' => $settings->smtp_host,
                'port' => $settings->smtp_port,
                'username' => $settings->username,
                'password' => $settings->password,
                'encryption' => $settings->encryption === 'none' ? null : $settings->encryption,
                'timeout' => null,
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
            ],
            'mail.from' => [
                'address' => $settings->from_address,
                'name' => $settings->from_name,
            ],
            // JUDGMENT CALL (CLAUDE.md §8 rule 1 — flagged, not silent):
            // spec §3.5 names only `mail.mailers.smtp` and `mail.from` as
            // the two keys this method overrides. Without ALSO flipping
            // `mail.default` to 'smtp' here, `.env`'s MAIL_MAILER=log
            // would still win and every mail send would keep going to the
            // log channel even with is_enabled=true — the whole feature
            // would be wired up but functionally inert. Overriding it only
            // inside this `is_enabled` branch preserves the fail-closed
            // half of the same rule: disabled still means exactly today's
            // MAIL_MAILER=log behavior, untouched.
            'mail.default' => 'smtp',
        ]);
    }
}
