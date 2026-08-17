<?php

namespace App\Services\Notification;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;

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
 */
class NotificationService
{
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
        return Notification::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'data' => $data,
        ]);
    }
}
