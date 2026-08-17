<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\ClientActivity;
use App\Notifications\FollowUpReminderNotification;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// TASK-016 (ADR-004) — runs every 5 minutes (routes/console.php). For
// each due, unnotified follow-up: claims the row by setting
// follow_up_notified_at = now() INSIDE the same DB::transaction() that
// reads it, BEFORE dispatching the queued notification — this is what
// makes the command idempotent if it somehow runs twice concurrently
// (the second run's query simply won't see a row that's already been
// claimed, since the claim commits before the notification is
// dispatched).
class DispatchDueFollowUpReminders extends Command
{
    protected $signature = 'reminders:dispatch-due-followups';

    protected $description = 'Send a follow-up reminder notification for every Client Activity whose follow_up_at is now due (TASK-016)';

    public function handle(): int
    {
        // withoutGlobalScope(TenantScope) — this command runs across
        // every tenant, not as any one company's user; it isn't an
        // access-control bypass since nothing here is exposed to a
        // client request (Section 5's TenantScope exists to stop
        // cross-tenant API responses, not background jobs that
        // legitimately operate platform-wide).
        $dueActivityIds = ClientActivity::withoutGlobalScopes()
            ->whereNotNull('follow_up_at')
            ->whereNull('follow_up_notified_at')
            ->where('follow_up_at', '<=', now())
            ->pluck('id');

        $dispatched = 0;

        foreach ($dueActivityIds as $activityId) {
            DB::transaction(function () use ($activityId, &$dispatched) {
                // Re-fetch + lock inside the transaction — between the
                // pluck() above and this point, another process could
                // have already claimed the row.
                $activity = ClientActivity::withoutGlobalScopes()
                    ->whereKey($activityId)
                    ->whereNull('follow_up_notified_at')
                    ->lockForUpdate()
                    ->first();

                if (! $activity) {
                    return;
                }

                $activity->update(['follow_up_notified_at' => now()]);

                $activity->loggedBy->notify(new FollowUpReminderNotification($activity));

                // TASK-053 Phase 2b — mirror the email into the agent's
                // in-app home bell (same claim-then-dispatch guard above
                // keeps this idempotent). loggedBy is the agent who owns
                // the follow-up, so company_id is stamped from them.
                app(NotificationService::class)->notify(
                    $activity->loggedBy,
                    NotificationType::FollowUpDue,
                    'ถึงกำหนดติดตามลูกค้า',
                    $activity->summary,
                    '/clients/'.$activity->client_id,
                    ['client_activity_id' => $activity->id, 'client_id' => $activity->client_id],
                );

                $dispatched++;
            });
        }

        $this->info("Dispatched {$dispatched} follow-up reminder(s).");

        return self::SUCCESS;
    }
}
