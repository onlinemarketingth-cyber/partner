Task: Renewal-Year Commission
Owner: ag-dev + ag-ui
Goal: Support a second, admin-configurable commission rate for annual renewals of a package, fired automatically on the renewal due date — not just the current one-time Complete Payment commission.
Related: ADR-006 (Commission Configuration Model), ADR-004 (Notification/Scheduled-Job Infrastructure — same `database` queue + `Schedule::command()` pattern, reused as-is), BR-2, BR-3, BR-4, BR-7

Input: `commission_rules` (existing), `referrals`/`clients` (existing — need a renewal-due date stamped somewhere), ADR-004's scheduled-command pattern (`DispatchDueFollowUpReminders` is the closest existing example to follow)

Expected output:
- Migration: `commission_rules` gains `renewal_rate_type` (nullable string), `renewal_rate_value` (nullable unsigned integer), `renewal_recurs` (boolean, default `false`). `NULL` renewal fields = "no renewal commission configured for this rule" — fully opt-in per (company × cert tier × product), never assumed.
- Migration: a `next_renewal_date` (nullable date) stamped somewhere reachable from a `referral` at the moment its Complete Payment ledger entry fires (`referrals.next_renewal_date`, or a small new `client_subscriptions` table if ag-dev finds that cleaner given the existing referral/client relationship — flag which was chosen and why).
- `App\Console\Commands\DispatchDueRenewalCommissions` (mirrors `DispatchDueFollowUpReminders`'s claim-then-dispatch shape): finds referrals where `next_renewal_date <= today` and a renewal rate is configured for that referral's (cert tier, product); inside a transaction, creates a new **immutable** `commission_ledger` row (BR-4 — never edits the original Complete Payment row) at the renewal rate, then either advances `next_renewal_date` by one year (`commission_rules.renewal_recurs = true`) or clears it (`false`, no further renewal fires for this referral).
- Registered in `routes/console.php`: `Schedule::command(DispatchDueRenewalCommissions::class)->daily()`.
- `commission_ledger` gains an `earned_via` column (`direct` | `renewal` | `override` — `override` reserved for TASK-025) so reports can distinguish renewal income from the original sale; existing rows backfill to `direct`.
- `frontend-admin`: the commission rule form gains the optional renewal-rate fields + a "ต่ออายุอัตโนมัติทุกปี" (recurs annually) toggle, only shown once a renewal rate is entered.
- Feature tests (`Notification::fake()`/time-travel via `Carbon::setTestNow()`, mirroring TASK-016's test style): a due renewal with `renewal_recurs=false` fires exactly once and never again; one with `renewal_recurs=true` fires again a year later; a referral with no renewal rate configured never fires; a not-yet-due renewal is untouched; running the command twice on the same day doesn't double-fire (idempotency, same claim-first pattern as TASK-016).

Acceptance Criteria:
  - A company that never sets a renewal rate sees zero behavior change (existing Complete Payment flow untouched)
  - Renewal commission is a genuinely new, separate, immutable ledger row — never a rewrite of the original (BR-4)
  - `renewal_recurs` correctly governs whether the renewal repeats every year or fires exactly once
  - Money is always integer satang (BR-3), rate is always read from `commission_rules` (BR-2/BR-7) — nothing hardcoded
  - `php artisan test` passes; `eslint`/`vue-tsc`/`vite build` clean (frontend-admin)

Out of scope (this task):
  - Any client-facing "your renewal is coming up" notification — this task is agent commission only; a client-facing reminder could reuse the same scheduled-job pattern later if wanted, not asked for here
  - Editing/canceling a renewal in progress from the UI — not asked for
  - Pro-rating a renewal rate if a client cancels mid-cycle — not asked for, flag if this becomes relevant

Depends on: none new (existing `commission_rules`/`commission_ledger`/`referrals` tables)
Blocks: none
