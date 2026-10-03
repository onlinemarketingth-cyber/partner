<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\Push\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 2026-10-02 — MOB-11. Push one notification to its recipient's phones.
 *
 * Dispatched by NotificationService::notify() after the surrounding
 * transaction commits, and queued (unlike the email, which sends inline)
 * because one notification can mean several HTTPS round-trips to Google
 * plus an OAuth exchange, and the producers include request paths that
 * fan out to every agent in a company.
 *
 * ── ONE ATTEMPT, NO RETRIES ──
 *
 * A retry re-sends to EVERY phone, including the ones that already got it.
 * A push is a nudge towards the bell, not the record; a missed one costs
 * the agent nothing they cannot see when they next open the app, while a
 * duplicated one is the kind of thing that gets notifications switched off.
 * So $tries = 1 and every failure is caught and logged here.
 *
 * ── WHEN IT DOES NOTHING ──
 *
 * The row is gone; the recipient was deactivated; the agent already read it
 * in the app (the queue worker runs from cron and can lag); or it is older
 * than notifications.push.stale_minutes.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $notificationId) {}

    public function handle(PushNotificationService $push): void
    {
        try {
            // Unscoped: a queue worker has no authenticated user, and under
            // the sync driver the acting user is whoever triggered the event
            // — rarely the recipient.
            $notification = Notification::withoutGlobalScopes()->find($this->notificationId);

            if ($notification === null || $notification->read_at !== null || $this->isStale($notification)) {
                return;
            }

            $recipient = User::withoutGlobalScopes()->find($notification->user_id);

            if ($recipient === null || $recipient->deleted_at !== null) {
                return;
            }

            $push->send($notification);
        } catch (Throwable $e) {
            Log::warning("SendPushNotification: push for notification #{$this->notificationId} failed — ".$e->getMessage());
        }
    }

    private function isStale(Notification $notification): bool
    {
        $minutes = (int) config('notifications.push.stale_minutes', 120);

        return $notification->created_at !== null
            && $notification->created_at->lt(now()->subMinutes($minutes));
    }
}
