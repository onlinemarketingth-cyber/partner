<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email delivery state for in-app notifications (human request 2026-08-22,
 * "ส่ง email แจ้งเตือนให้กับ Agent").
 *
 * ── WHY THE STATE LIVES ON THE NOTIFICATION ROW ──
 *
 * The obvious build is "call Mail::send() next to Notification::create()".
 * That fails two ways this codebase has already been bitten by:
 *
 *  1. NO QUEUE WORKER IS GUARANTEED. NewAgentRegistrationNotification's
 *     docblock records the 2026-08-17 fix: with QUEUE_CONNECTION=database
 *     and no `queue:work` running, a ShouldQueue notification inserts a
 *     `jobs` row and returns — no email, no error, nothing to look at.
 *     Anything that can only succeed via the queue is a silent failure
 *     waiting to happen.
 *
 *  2. AN ANNOUNCEMENT IS N RECIPIENTS. AnnouncementService loops over every
 *     targeted agent inside the admin's POST. One SMTP round-trip each, on a
 *     company with a few hundred agents, is a request that times out — and
 *     the announcement is already saved by then, so the admin sees a failure
 *     for something that partly worked.
 *
 * So the row records what it wants and what has happened to it:
 *
 *   email_due_at   NULL = this notification does not want an email at all.
 *                  Set = it does, and may be sent from this moment on.
 *   emailed_at     NULL = not sent yet. Set = sent, never send again.
 *   email_attempts A permanently failing address (bounced, malformed) must
 *                  stop being retried, or one bad row is mailed forever at
 *                  every sweep. Capped by notifications.email.max_attempts.
 *
 * Single-recipient events still send INLINE (see NotificationService), so the
 * emails an agent actually waits on — approved, commission paid, payment
 * confirmed — need no worker and no cron. The sweep command exists for the
 * deferred kinds (announcements) and as the retry path for anything whose
 * inline attempt failed. That means a broken sweep degrades announcements
 * from "emailed" to "in-app only" instead of taking every email down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('email_due_at')->nullable()->after('read_at');
            $table->timestamp('emailed_at')->nullable()->after('email_due_at');
            $table->unsignedTinyInteger('email_attempts')->default(0)->after('emailed_at');

            // The sweep's exact predicate: due, not yet sent. Attempts and
            // the staleness window are filtered inside the (already small)
            // result, so they stay out of the index.
            $table->index(['email_due_at', 'emailed_at'], 'notifications_email_pending_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_email_pending_index');
            $table->dropColumn(['email_due_at', 'emailed_at', 'email_attempts']);
        });
    }
};
