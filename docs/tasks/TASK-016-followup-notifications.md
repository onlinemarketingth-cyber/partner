Task: Follow-up Reminder Notifications (Email)
Owner: ag-dev
Goal: Send the logging agent an email when a Client Activity's `follow_up_at` becomes due, per ADR-004's chosen architecture (queued, near-real-time, Email channel first).
Related: ADR-004 (Notification Infrastructure), TASK-015 (owns the `client_activities` table this reads from), CLAUDE.md Section 6 (audit — the dispatch itself doesn't need a separate audit-log entry; `follow_up_notified_at` on the source row already answers "was this notified")

Input: `client_activities.follow_up_at` / `follow_up_notified_at` (TASK-015), `users.email` (existing), Laravel's built-in Notification/Mail/Queue system (already in `laravel/framework`, no new package)

Expected output:
- `App\Console\Commands\DispatchDueFollowUpReminders` — queries `client_activities` where `follow_up_at <= now()` and `follow_up_notified_at IS NULL`; for each row, inside one `DB::transaction()`: sets `follow_up_notified_at = now()` (claims the row, preventing double-dispatch) and dispatches `SendFollowUpReminderNotification` (a `ShouldQueue` job).
- Registered in `routes/console.php`: `Schedule::command(DispatchDueFollowUpReminders::class)->everyFiveMinutes()`.
- `App\Notifications\FollowUpReminderNotification extends Notification implements ShouldQueue` — `via()` returns `['mail']` only for now (a LINE channel is appended later per ADR-004, once the deferred LINE OA credentials exist — no changes needed to the Activity Log or dispatch command when that happens). `toMail()` includes the client's name, the activity's summary, and a link to the client's page in the Agent Portal.
- Sent to the `logged_by_user_id` User (the agent who set the follow-up) — not necessarily the client's current `referring_agent_id`, since they can differ if a Company Admin logged the activity on an agent's behalf.
- Feature tests (using Laravel's `Notification::fake()`): a due follow-up triggers exactly one notification to the correct agent; a not-yet-due follow-up triggers none; an already-notified follow-up isn't re-sent even if the command runs twice in a row; a row with no `follow_up_at` is never picked up.

Acceptance Criteria:
  - Running the Artisan command against a due, unnotified follow-up sends exactly one email to the logging agent
  - Running it again immediately after does NOT send a second email for the same activity (idempotency)
  - A follow-up more than 5 minutes in the future is not yet picked up — only genuinely due rows are dispatched
  - `MAIL_MAILER=log` (dev default) still lets the whole flow be tested end-to-end without a real SMTP account — the human only needs to supply real SMTP credentials before this reaches production
  - `php artisan test` passes; no changes required to TASK-015's frontend work

Out of scope (this task):
  - LINE OA channel — blocked on the human supplying a Channel Access Token + Channel Secret (ADR-004); this task's `via()` design leaves a clean seam to add it later
  - Any in-app "overdue" badge/UI — could be added cheaply to TASK-015's activity list once this ships (e.g. a marker where `follow_up_at` is past but this was the trigger that resolved it), but wasn't asked for; flag if wanted
  - Retrying failed sends beyond Laravel's queue's own default retry behavior — no custom backoff/alerting logic

Depends on: TASK-015 (needs `client_activities.follow_up_at`/`follow_up_notified_at` to exist first)

Blocked on (human action required before this reaches production):
  - Real SMTP credentials in `.env` (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`) — until supplied, emails only write to `storage/logs/laravel.log` (safe for dev/testing, useless for real reminders)
  - A persistent `php artisan queue:work` process running on whichever host serves the backend (not just the built-in PHP dev server) — to be noted in `SETUP.md` once hosting is decided
