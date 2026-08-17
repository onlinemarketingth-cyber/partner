# ADR-004: Follow-up Reminder Notification Infrastructure (Email-first)

- **Date:** 2026-07-13
- **Status:** Accepted (approved by human) — LINE OA channel explicitly deferred pending credentials
- **Author:** ag-lead

## Context

A CRM-standards comparison (2026-07-13) identified "Activity / Communication Log with follow-up reminders" as a standard CRM feature this system lacks. The human confirmed the reminder must be a real notification (not just an in-app badge), starting with Email — LINE OA to follow once a Channel Access Token + Channel Secret exist — and that delivery should be near-real-time (queued dispatch), not a once-daily batch.

This is the first notification/background-job infrastructure in the project; nothing in CLAUDE.md Section 3 ("Architecture — Decided") covers queues or outbound mail, so it needs its own decision record.

## Options Considered

1. **Once-daily Scheduler check, synchronous send, no queue worker.** Simplest ops (one cron line, nothing else to keep running). Rejected — human wants near-real-time delivery, and a slow SMTP call would block the scheduler run for every other scheduled task.
2. **Frequent Scheduler check (every 5 minutes) dispatching a queued Job per due follow-up, sent by a `php artisan queue:work` background worker, `database` queue driver.** Slightly more ops (a persistent worker process, in addition to the cron), but reliable, retryable via Laravel's built-in queue retry behavior, and near-real-time. **Chosen.**
3. **Redis/SQS queue driver instead of `database`.** Rejected for now — no Redis anywhere in the stack (CLAUDE.md Section 3 names MySQL 8 as the only decided datastore), and the `jobs` table migration already ships with this Laravel 12 skeleton (`0001_01_01_000002_create_jobs_table.php`) — the `database` driver needs zero new infrastructure.

## Decision

- **Channel, this sprint: Email only.** `MAIL_MAILER` — the human must supply real SMTP credentials in `.env` before this reaches production; it currently defaults to the `log` driver (writes to `storage/logs/laravel.log`, sends nothing real — safe for dev/testing the whole flow end-to-end).
- **LINE OA channel: explicitly deferred**, blocked on the human supplying a Channel Access Token + Channel Secret. When ready, it's added as a second Laravel Notification channel (`via()` gains `'line'` alongside `'mail'`) on the same trigger/Job — no changes needed to the Activity Log or the dispatch command.
- **Delivery mechanism:** `QUEUE_CONNECTION=database` (already this project's default — see `backend/.env.example`). A new scheduled Artisan command checks for due follow-ups every 5 minutes (`Schedule::command(...)->everyFiveMinutes()` in `routes/console.php`) and dispatches one `ShouldQueue` Job per due reminder. A separate long-running `php artisan queue:work` process (started via systemd/supervisor in production, run manually in a second terminal for local dev) sends the actual email.
- **Idempotency:** `client_activities.follow_up_notified_at` (nullable timestamp, owned by TASK-015) is set the moment the Job is *dispatched*, not once actually sent — the scheduled check's query excludes rows where this is already set, so a follow-up is never queued twice even if the checker runs again before the queue has drained.

## Consequences

- **New operational requirement:** production hosting needs a persistent `queue:work` process, not just a request-triggered PHP process — flagged for `SETUP.md` once hosting is decided (out of scope for this ADR to resolve).
- **Local dev:** the human runs `php artisan queue:work` in a second terminal (or sets `QUEUE_CONNECTION=sync` locally as a dev-only convenience — never used in production, since `sync` skips real retry/queue behavior entirely).
- **Cron dependency:** exactly one cron entry (`* * * * * php artisan schedule:run`) must exist on the host — the standard mechanism Laravel already expects for any scheduled task, nothing specific to this feature.
- Real email sending requires the human to supply SMTP credentials; until then the feature is fully testable (including in CI) via `MAIL_MAILER=log` and Laravel's `Notification::fake()`.
- LINE OA integration becomes a small follow-up task once credentials exist — not blocking this sprint.

## Related

- CLAUDE.md Section 3 (Architecture — MySQL 8 is the only decided datastore; no Redis), Section 6 (Audit Log)
- TASK-015 (Client Activity Log — owns `client_activities.follow_up_at` / `follow_up_notified_at`)
- TASK-016 (Follow-up Reminder Notifications — implements this ADR)
