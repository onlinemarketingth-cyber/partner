<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Services\Notification\NotificationMailer;
use Illuminate\Console\Command;

/**
 * Sends the notification emails that could not be sent inline.
 *
 * Two kinds of row land here:
 *
 *  1. DEFERRED TYPES. An announcement notifies every targeted agent inside
 *     the admin's POST; sending N emails on that request would time it out
 *     (config/notifications.php explains the choice). Those rows are stamped
 *     and left for this command to send in bounded batches.
 *
 *  2. RETRIES. Anything whose inline send threw — mail server down, DNS
 *     blip — kept email_due_at set and is picked up here, up to
 *     max_attempts.
 *
 * ── WHAT HAPPENS IF THIS NEVER RUNS ──
 *
 * Worth stating plainly, because routes/console.php notes that a real cron
 * entry calling `schedule:run` is a hosting to-do this project has not
 * confirmed. If the scheduler is not running: announcements are in-app only,
 * and a failed inline send is never retried. Everything an agent is actually
 * waiting on — approved, rejected, commission paid, payment confirmed —
 * still emails, because those send inline and depend on neither cron nor a
 * queue worker. The blast radius of a missing cron is deliberately bounded
 * to the least urgent category.
 *
 * `--limit` exists so a backlog can be drained by hand (or cautiously, a few
 * at a time) without waiting for the schedule or editing config.
 */
class DispatchPendingNotificationEmails extends Command
{
    protected $signature = 'notifications:send-emails {--limit= : Rows to process this run (defaults to notifications.email.batch_size)}';

    protected $description = 'Send queued notification emails (deferred types and retries)';

    public function handle(NotificationMailer $mailer): int
    {
        $limit = (int) ($this->option('limit') ?: config('notifications.email.batch_size', 200));
        $maxAttempts = (int) config('notifications.email.max_attempts', 3);
        $staleHours = (int) config('notifications.email.stale_hours', 24);

        // withoutGlobalScopes: the console has no authenticated user, so
        // TenantScope would resolve to no company and match nothing. Every
        // row still carries the recipient's own company_id, and the mailer
        // reads the address off the recipient — nothing here can cross
        // tenants, it just has to be able to SEE all of them.
        $pending = Notification::withoutGlobalScopes()
            ->with('user')
            ->whereNotNull('email_due_at')
            ->whereNull('emailed_at')
            ->where('email_attempts', '<', $maxAttempts)
            ->where('email_due_at', '<=', now())
            // Nobody wants "your account was approved" two days late. Past
            // the window the in-app notification stands on its own.
            ->where('email_due_at', '>=', now()->subHours($staleHours))
            ->orderBy('email_due_at')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($pending as $notification) {
            $mailer->send($notification) ? $sent++ : $failed++;
        }

        $this->info("notifications:send-emails — sent {$sent}, failed {$failed}, scanned {$pending->count()}");

        // A count equal to the limit means there is very likely more waiting.
        // Said out loud so a backlog is visible in the scheduler's output
        // rather than only in a slowly growing table.
        if ($pending->count() === $limit) {
            $this->warn('Batch was full — more rows are probably still pending.');
        }

        return self::SUCCESS;
    }
}
