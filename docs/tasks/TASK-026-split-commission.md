Task: Split Commission Between Co-Selling Agents
Owner: ag-dev + ag-ui
Goal: Let a referral optionally credit two agents (the original referring agent + one co-agent) with commission on the same sale, splitting the amount by a percentage chosen per referral (not a fixed company-wide ratio).
Related: ADR-006 (Commission Configuration Model), BR-2, BR-3, BR-4, CLAUDE.md §4.3 (Pipeline), ReferralPolicy/ReferralService (existing)

Input: `referrals` (existing, currently exactly one `referring_agent_id`), `CommissionService`'s existing direct-sale calculation

Expected output:
- Migration: `referrals` gains a nullable `co_agent_id` (FK → `users.id`, same-company validated in the Service layer, restrictOnDelete) and `split_percentage` (nullable unsigned tinyint, 1–99 — the co-agent's share; `referring_agent_id` always keeps `100 - split_percentage`). Both `NULL` = today's exact behavior (single agent, no split) — fully opt-in, zero change for a referral that never sets a co-agent.
- Validation: `co_agent_id` required together with `split_percentage` (both-or-neither); `co_agent_id` must differ from `referring_agent_id`; `split_percentage` must be 1–99 (0 or 100 would just mean "not actually split" — reject, ask the agent to leave the fields empty instead).
- `ReferralService`: allow `co_agent_id`/`split_percentage` to be set at referral creation, and editable up until the referral reaches Complete Payment (matches when `CommissionService` actually reads them) — not editable after, since the ledger is already immutable (BR-4) by then.
- `CommissionService` change: at the Complete Payment trigger, if `co_agent_id` is set, calculate the total commission exactly as today, then create **two** `commission_ledger` rows instead of one — `referring_agent_id` for `(100 - split_percentage)%` of the amount, `co_agent_id` for `split_percentage%` — both `earned_via = 'direct'` (a split sale is still a direct sale for both parties, not an override — see TASK-025). Rounding: split amounts in satang, any 1-satang remainder from rounding goes to `referring_agent_id` (never silently dropped — BR-3).
- `frontend`/Agent Portal: on the referral form, an optional "แบ่งคอมมิชชั่นกับตัวแทนอีกคน" (split commission with another agent) toggle revealing a same-company agent picker + a percentage input.
- Feature tests: a referral with no co-agent produces exactly one ledger row (today's behavior, unchanged); one with a co-agent produces two rows summing to the same total as before (rounding remainder verified); a co-agent from another company is rejected (BR-6); `split_percentage` outside 1–99 is rejected; editing the co-agent/split after Complete Payment is rejected (ledger already fired).

Acceptance Criteria:
  - A referral that never sets a co-agent behaves identically to today — single ledger row, single agent
  - The two split ledger rows always sum to exactly the total commission amount (no satang lost or invented — BR-3)
  - Cross-company co-agent assignment is impossible (BR-6), proven by test, not just UI hiding
  - `php artisan test` passes; `eslint`/`vue-tsc`/`vite build` clean (Agent Portal)

Out of scope (this task):
  - More than 2 agents on one referral (an explicit pair only, matching what was actually asked for) — flag if N-way splitting is wanted later
  - Splitting an override commission (TASK-025) between two managers — only the direct sale commission is splittable in this task
  - A default/remembered split ratio per agent pair — every referral's split is chosen fresh, no "usual partner" shortcut (not asked for)

Depends on: none new (existing `referrals`/`commission_ledger`/`CommissionService`)
Blocks: none
