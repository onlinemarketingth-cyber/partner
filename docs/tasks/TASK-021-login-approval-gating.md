Task: Login-flow gating for unverified/pending/rejected registrations
Owner: ag-dev + ag-ui
Goal: Make the approval/verification state built in TASK-017/018/019 actually mean something at login — block sign-in with a clear, honest, distinct message for each non-approved state, rather than a generic "invalid credentials" that would confuse someone whose account genuinely exists.
Related: ADR-005, CLAUDE.md Section 6 (clear, non-misleading error messaging is a UX/security-clarity concern, not a leak — the person already knows their own email/account exists)

Input: `AuthController::login` (existing), TASK-017's `agent_approval_status` + `email_verified_at`

Expected output:
- `AuthController::login` (or a small `LoginGate` check called from it): after credentials are verified correct but before issuing a session, check in this order — (1) email not verified (email-path accounts only) → 403 with a distinct error code/message; (2) `agent_approval_status = pending` → 403, different message; (3) `agent_approval_status = rejected` → 403, different message (include the rejection reason if one was recorded). Only `approved` + verified proceeds to normal session issuance.
- Agent Portal `LoginView.vue`: renders each of the three distinct messages appropriately (e.g. a "resend verification email" action on the unverified case, since that's actionable; the pending/rejected cases are informational only).
- A `POST /api/v1/register/resend-verification-email` endpoint (rate-limited), for the one actionable case above.
- Feature tests: login with correct credentials but unverified email → blocked with the unverified message; login with verified-but-pending → blocked with the pending message; login with rejected → blocked with the rejected message (+ reason if present); login with approved + verified → succeeds normally, unchanged from today's behavior; existing (pre-this-feature) accounts, all backfilled to `approved` by TASK-017, are completely unaffected — this is the regression test that matters most here.

Acceptance Criteria:
  - None of the three blocked states are ever confused with "wrong password" — each has its own distinct, correct message
  - No behavior change whatsoever for any account created before this feature (backfilled to `approved`) or via the existing Company-Admin-creates-Agent flow (which still defaults straight to `approved`, per ADR-005)
  - Resend-verification endpoint is rate-limited (Section 6)
  - `eslint` / `vue-tsc --build` / `vite build` clean (Agent Portal); `php artisan test` passes, including the "nothing changed for existing accounts" regression tests

Out of scope (this task):
  - Any change to `frontend-admin`'s own login (Company Admin/Super Admin accounts are never created via this self-registration path, so they're never subject to this gate — flag if that assumption is wrong)
  - CAPTCHA on login itself (only the registration/resend endpoints were called out for rate limiting in ADR-005's decision; login already has its own existing rate limiting per CLAUDE.md Section 6, unchanged by this task)

Design notes (flag if wrong):
  - Rejected accounts are not deleted or hard-blocked from ever registering again (ADR-005's "Open items" — proposed default: they may submit a fresh registration) — this task only blocks the *existing* rejected account from logging in, it does not add any additional lockout beyond that.

Depends on: TASK-017, TASK-018, TASK-019
Blocks: none (last task in this feature's initial sprint)
