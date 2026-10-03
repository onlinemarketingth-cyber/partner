<?php

namespace App\Services\Notification\Push;

use App\Models\Company;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Support\NotificationLink;

/**
 * 2026-10-02 — MOB-11. Turns one in-app notification into one push per
 * phone of its recipient.
 *
 * Called from App\Jobs\SendPushNotification, which NotificationService
 * dispatches after commit — so by the time this runs, the notification row
 * is real and the bell already shows it. Nothing here can change that row or
 * the email path; the worst outcome of any failure below is a phone that
 * did not buzz.
 *
 * ── WHAT THE PUSH SAYS (PDPA, CLAUDE.md §6) ──
 *
 * Never the notification's own title or body — those can name a client, and
 * a push is read on a locked screen by whoever holds the phone. The body is a
 * fixed sentence per type from config('notifications.push'); see that block
 * for the reasoning.
 *
 * ── THE TITLE IS THE RECIPIENT'S COMPANY (owner decision 2026-10-03) ──
 *
 * "ใช้ตามชื่อบริษัท". The title is `companies.name` of the notification's
 * company — the same column the agent portal shows as the company name on
 * its login page and as the merchant on the payment page, so the lock screen
 * names the company the agent signed up with. It is the notification's
 * company_id that is used, not the user's current one: the row was stamped
 * with the recipient's company when it was created (NotificationService), and
 * that is the company the event happened in.
 *
 * Deliberately NOT the theme's `label_overrides.app_name` (the header
 * wordmark): that is an optional APP name a company may set ("Thai Life
 * Agent"), and when unset the header shows the platform name — the very
 * thing the owner asked to replace.
 *
 * A company name is not personal data, so it may sit on a lock screen. Only
 * when the company or its name is missing does the title fall back to
 * config('notifications.push.title'). Whitespace is collapsed (a name typed
 * with a newline must not become a two-line title) and the result is cut to
 * TITLE_MAX_CHARS, which is about what a lock screen shows before it
 * ellipsises anyway.
 *
 * The data payload carries only the notification id and the in-app path to
 * open when tapped. The path comes from NotificationLink, the resolver the
 * email already uses, so a push and a mail about the same event open the
 * same screen; a notification with nowhere to go opens the list.
 */
class PushNotificationService
{
    public const FALLBACK_URL = '/notifications';

    public const TITLE_MAX_CHARS = 60;

    public function __construct(private FcmClient $fcm) {}

    public function isEnabled(): bool
    {
        return $this->fcm->isConfigured();
    }

    /**
     * Does this user have any phone to push to? Asked before a job is
     * queued, so an agent who only ever uses the web costs one indexed
     * lookup per notification rather than a `jobs` row.
     *
     * Unscoped and narrowed by user_id explicitly: this runs inside
     * whatever request produced the notification, whose authenticated user
     * is usually NOT the recipient (an admin approving an agent).
     */
    public function hasDevices(int $userId): bool
    {
        return DeviceToken::withoutGlobalScopes()->where('user_id', $userId)->exists();
    }

    public function send(Notification $notification): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $devices = DeviceToken::withoutGlobalScopes()
            ->where('user_id', $notification->user_id)
            ->get();

        if ($devices->isEmpty()) {
            return;
        }

        // Resolved once per notification, not once per phone.
        $title = $this->titleFor($notification);

        foreach ($devices as $device) {
            $outcome = $this->fcm->send($this->message($notification, $device, $title));

            if ($outcome === FcmSendOutcome::TokenGone) {
                // Uninstalled, or the token rotated. Left in place it would be
                // tried again on every notification this user ever gets.
                $device->delete();
            }
        }
    }

    /**
     * The FCM v1 `message` for one device. Public for the tests that pin
     * what may and may not appear on a lock screen.
     *
     * @return array<string, mixed>
     */
    public function message(Notification $notification, DeviceToken $device, ?string $title = null): array
    {
        $title ??= $this->titleFor($notification);
        $body = $this->bodyFor($notification);

        return [
            'token' => $device->token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            // FCM requires every data value to be a string.
            'data' => [
                'notification_id' => (string) $notification->id,
                'url' => $this->urlFor($notification),
            ],
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                ],
            ],
            'apns' => [
                'headers' => [
                    'apns-priority' => '10',
                    'apns-push-type' => 'alert',
                ],
                'payload' => [
                    'aps' => [
                        'alert' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'sound' => 'default',
                    ],
                ],
            ],
        ];
    }

    /**
     * The recipient's company name, or the configured app label when there
     * is none. See the class docblock.
     *
     * Unscoped: a queue worker has no authenticated user, and under the sync
     * driver the acting user may be a Super Admin or another company's
     * admin. withoutGlobalScopes() also drops SoftDeletingScope, so a closed
     * company still names its own last notifications.
     */
    public function titleFor(Notification $notification): string
    {
        $name = Company::withoutGlobalScopes()
            ->whereKey($notification->company_id)
            ->value('name');

        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

        if ($name === '') {
            return (string) config('notifications.push.title', 'Live to 100 Club');
        }

        return mb_strlen($name) > self::TITLE_MAX_CHARS
            ? rtrim(mb_substr($name, 0, self::TITLE_MAX_CHARS - 1)).'…'
            : $name;
    }

    private function bodyFor(Notification $notification): string
    {
        $bodies = (array) config('notifications.push.bodies', []);
        $body = $bodies[$notification->type->value] ?? null;

        return is_string($body) && $body !== ''
            ? $body
            : (string) config('notifications.push.fallback_body', 'มีการแจ้งเตือนใหม่');
    }

    /**
     * A RELATIVE in-app path, always. NotificationLink already refuses
     * anything that does not start with '/', so an absolute URL stored in
     * `link` can never turn a push into an off-site redirect. '//host' is
     * refused here as well: it starts with '/' but a browser reads it as
     * another origin.
     */
    private function urlFor(Notification $notification): string
    {
        $path = NotificationLink::for($notification);

        if ($path === null || str_starts_with($path, '//')) {
            return self::FALLBACK_URL;
        }

        return $path;
    }
}
