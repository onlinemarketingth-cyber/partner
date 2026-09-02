<?php

namespace App\Support;

use App\Models\User;

/**
 * Which name and link an outgoing email should wear.
 *
 * ── WHY THIS EXISTS ──
 *
 * Every mail used to render config('app.name') in its header, its footer and
 * its subject. That is ONE name for a platform that hosts many companies, so
 * a recruit signing up for "Thai Life insurance" received a mail branded with
 * the platform's own product name — a mail that says one thing at the top and
 * another in the subject is exactly what a phishing attempt looks like, and
 * the recipient has no way to tell the difference.
 *
 * The tenant's own name is the honest answer, and it already exists:
 * companies.name is what the admin console, the agent portal and the
 * certificate PDF all display.
 *
 * ── THE FALLBACK IS NOT A DEFAULT ──
 *
 * config('app.name') is returned only when there is genuinely no company to
 * name: a Super Admin (company_id is null by design), the platform SMTP test,
 * a notifiable that is not a User. Those mails are ABOUT the platform, so the
 * platform's name is correct there rather than a stand-in.
 */
final class MailBrand
{
    /**
     * The brand name for a mail addressed to this user.
     *
     * loadMissing, not load: the caller has often already eager-loaded the
     * relation, and a notification that fires per-recipient must not turn one
     * query into one per mail.
     */
    public static function forUser(mixed $user): string
    {
        if (! $user instanceof User) {
            return self::platform();
        }

        $user->loadMissing('company');

        return trim((string) $user->company?->name) ?: self::platform();
    }

    public static function platform(): string
    {
        return (string) config('app.name');
    }

    /**
     * Where the brand in the mail header links to.
     *
     * config('app.url') — the vendor default — is the API root, which answers
     * a human with JSON. A person clicking the name at the top of the mail
     * wants the app they use, so each notification says which portal that is.
     */
    public static function agentPortalUrl(): string
    {
        return rtrim((string) config('services.agent_portal.frontend_url'), '/') ?: (string) config('app.url');
    }

    public static function adminPortalUrl(): string
    {
        return rtrim((string) config('services.company_admin_portal.frontend_url'), '/') ?: (string) config('app.url');
    }
}
