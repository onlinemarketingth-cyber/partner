<?php

use App\Console\Commands\DispatchDueFollowUpReminders;
use App\Console\Commands\DispatchDueRenewalCommissions;
use App\Console\Commands\DispatchPendingNotificationEmails;
use App\Console\Commands\PayDueAgentPromotionCredits;
use App\Console\Commands\PruneChunkedUploadsCommand as PruneChunkedUploads;
use App\Console\Commands\RecalculateAgentRanks;
use App\Console\Commands\RunDueBinaryMatchingCycles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// TASK-016 (ADR-004) — near-real-time is "every 5 minutes", not
// instant; a persistent `php artisan schedule:work` (or a real cron
// entry calling `schedule:run` every minute) must be running for this
// to actually fire — see SETUP.md once hosting is decided.
Schedule::command(DispatchDueFollowUpReminders::class)->everyFiveMinutes();

// 2026-08-22 — notification emails that could not be sent inline: the
// deferred types (announcements, which fan out to every agent in one
// request) plus retries of any inline send that threw. Five minutes matches
// the reminder cadence above and shares its cron dependency.
//
// withoutOverlapping because a large announcement can take longer than the
// gap to the next run, and two sweeps racing the same rows would both try to
// claim them. The mailer's exactly-once check would hold, but the second run
// would burn a full batch scanning rows the first already took.
Schedule::command(DispatchPendingNotificationEmails::class)->everyFiveMinutes()->withoutOverlapping();

// TASK-024 (ADR-006) — renewal dates are calendar days, not minutes;
// daily is enough and keeps this cheap (same infra as above, just a
// coarser cadence).
Schedule::command(DispatchDueRenewalCommissions::class)->daily();

// ADR-011/TASK-029 — cycle_frequency itself is the coarsest cadence
// (weekly/biweekly/monthly, commission_binary_settings, BR-7); a daily
// check is enough to catch every due cycle without running the sweep
// needlessly often, same reasoning as the renewal command above.
Schedule::command(RunDueBinaryMatchingCycles::class)->daily();

// ADR-011/TASK-031 — agent_rank_settings.recalculation_frequency is the
// coarsest cadence (daily/weekly/monthly, BR-7); a daily check is
// enough to catch every due company, same reasoning as the Binary
// cycle command above.
Schedule::command(RecalculateAgentRanks::class)->daily();

// TASK-042 §3 — payout_timing = monthly_batch promotions accumulate
// agent_promotion_credits all month; this pays every still-unpaid one
// out on a monthly cadence (there is no finer per-company setting for
// this feature, unlike Binary/Stairstep's cycle_frequency/
// recalculation_frequency — "monthly" IS the confirmed cadence).
Schedule::command(PayDueAgentPromotionCredits::class)->monthly();

// TASK-094 — abandoned chunked-upload .part files. Hourly rather than
// daily: on the production host (shared hosting, 600K inode quota) a
// day's worth of interrupted 44MB video uploads is real disk, and the
// command is cheap when there is nothing to prune.
Schedule::command(PruneChunkedUploads::class)->hourly();
