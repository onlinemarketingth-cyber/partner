<?php

namespace App\Services\Notification;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TASK-053 / ADR-016 Phase 1 — the single place anything in the platform
 * creates a notification for a user. Phase 2 wires the domain events
 * (announcement published, exam pass/fail, commission paid, follow-up
 * due, approval change, reward) to call notify(); Phase 1 just provides
 * the creation API + tests.
 *
 * company_id is always taken from the RECIPIENT (never trusted from a
 * caller), so a notification can never be mis-tenanted. read_at starts
 * null (unread).
 *
 * ─────────────────────────────────────────────────────────────────────
 * EMAIL (human request 2026-08-22, "ส่ง email แจ้งเตือนให้กับ Agent")
 *
 * Every caller already funnels through here, so this is the one place that
 * can decide whether an event also leaves the app — no producer needs to
 * know about mail, and none of them changed.
 *
 * Three questions, in order, and all three must pass:
 *   1. Does this TYPE email at all?  config('notifications.email.types')
 *   2. Can this RECIPIENT be emailed? A real address, and their own
 *      preference (users.email_notifications_enabled) still on.
 *   3. Should it go NOW or be swept? Deferred types (announcements, which
 *      fan out to every agent in one request) are stamped and left for
 *      `notifications:send-emails`.
 *
 * ── WHY DB::afterCommit ──
 *
 * OrderService::confirmPayment() calls notify() from INSIDE a transaction
 * that also writes the order and the commission ledger. Sending SMTP there
 * would hold row locks open for the length of a network round-trip to a mail
 * server, on the hottest write path in the system. Worse, a rollback after
 * the send — a later constraint failing, a deadlock retry — would have
 * already told the agent their money was paid when the ledger row no longer
 * exists.
 *
 * afterCommit defers to the moment the outermost transaction commits, and
 * runs immediately when there is no transaction open. So the mail goes out
 * only if the fact it describes is actually true, and every existing caller
 * gets that for free without knowing it.
 */
class NotificationService
{
    public function __construct(private NotificationMailer $mailer) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function notify(
        User $user,
        NotificationType $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?array $data = null,
    ): Notification {
        $notification = Notification::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'data' => $data,
            // Stamped at creation rather than decided at send time, so the
            // row itself records the intent. A config change later must not
            // retroactively email a month of history.
            'email_due_at' => $this->wantsEmail($user, $type) ? now() : null,
        ]);

        if ($notification->email_due_at !== null && ! $this->isDeferred($type)) {
            // The row is reloaded inside the callback: by commit time the
            // instance held here may be stale, and the mailer's exactly-once
            // claim has to act on current state.
            $id = $notification->id;
            DB::afterCommit(function () use ($id) {
                $fresh = Notification::withoutGlobalScopes()->with('user')->find($id);

                if ($fresh !== null) {
                    $this->mailer->send($fresh);
                }
            });
        }

        return $notification;
    }

    /**
     * Does this event, for this recipient, warrant an email?
     *
     * The agent's own preference is checked here AND again in the mailer.
     * That is not redundancy for its own sake: a deferred notification can
     * sit for minutes before the sweep reaches it, and an agent who switches
     * emails off in that window must not receive the one already in flight.
     */
    private function wantsEmail(User $user, NotificationType $type): bool
    {
        if (blank($user->email) || ! $user->email_notifications_enabled) {
            return false;
        }

        return (bool) (config('notifications.email.types')[$type->value] ?? false);
    }

    private function isDeferred(NotificationType $type): bool
    {
        return in_array($type->value, config('notifications.email.deferred_types', []), true);
    }
}
