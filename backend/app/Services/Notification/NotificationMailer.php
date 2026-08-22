<?php

namespace App\Services\Notification;

use App\Models\Notification;
use App\Notifications\AgentNotificationEmail;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the email form of a notification row, exactly once, and never lets
 * that failure become the caller's failure.
 *
 * ── THE RULE THIS CLASS EXISTS TO ENFORCE ──
 *
 * A notification is a side effect of something that already happened. The
 * commission WAS paid. The agent WAS approved. The customer's payment DID
 * clear. If the mail server is down, misconfigured, or simply slow, the
 * correct outcome is "the business fact stands and the in-app notification is
 * there; the email did not go out" — never "the approval failed because SMTP
 * timed out".
 *
 * That is why send() catches Throwable. It is the one place in this codebase
 * where swallowing an exception is the safe behaviour rather than the lazy
 * one, and it is safe only because the failure is RECORDED: email_attempts
 * increments, the reason is logged with the row id, and email_due_at stays
 * set so the sweep retries it. A failure that leaves no trace would be the
 * lazy version.
 *
 * ── AND EXACTLY ONCE ──
 *
 * Two paths can reach the same row: the inline send at notify() time and the
 * sweep command picking up anything unsent. `emailed_at` is stamped BEFORE
 * the send, not after, so a crash mid-send loses one email rather than
 * mailing the same agent on every sweep for a day. Losing one notification
 * email is a small harm; a loop that mails somebody 288 times is the kind
 * that gets a sending domain blacklisted.
 */
class NotificationMailer
{
    /**
     * @return bool whether the mail was handed to the mailer without error
     */
    public function send(Notification $notification): bool
    {
        // withoutGlobalScopes on the reload: the sweep runs in the console
        // with no authenticated user, so TenantScope would resolve to no
        // company and find nothing.
        if ($notification->emailed_at !== null || $notification->email_due_at === null) {
            return false;
        }

        $recipient = $notification->user;

        // A recipient can lose their address (soft-deleted user, an admin
        // clearing the field) between the row being written and the sweep
        // reaching it. Abandon rather than retry: no attempt count will ever
        // conjure an address.
        if ($recipient === null || blank($recipient->email) || ! $recipient->email_notifications_enabled) {
            $notification->forceFill(['email_due_at' => null])->save();

            return false;
        }

        // Claim the row first — see the docblock. A double-send is worse
        // than a lost send.
        $notification->forceFill([
            'emailed_at' => now(),
            'email_attempts' => $notification->email_attempts + 1,
        ])->save();

        try {
            $recipient->notify(new AgentNotificationEmail($notification));

            return true;
        } catch (Throwable $e) {
            // Hand the row back to the sweep, keeping the incremented attempt
            // count so a permanently broken address stops at max_attempts.
            $notification->forceFill(['emailed_at' => null])->save();

            Log::warning('Notification email failed', [
                'notification_id' => $notification->id,
                'user_id' => $recipient->id,
                'type' => $notification->type?->value,
                'attempts' => $notification->email_attempts,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
