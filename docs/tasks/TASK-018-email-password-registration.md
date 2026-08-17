Task: Email/password self-registration + email verification
Owner: ag-dev + ag-ui
Goal: Let a person register as an Agent with their own email + password, bound to a specific company via an invite code, with mandatory email verification before entering the Company Admin approval queue (ADR-005 decisions 1 and 4).
Related: ADR-005, CLAUDE.md Section 5 (BR-6 — tenant binding via invite code, never a public company list), Section 6 (rate limiting on every public endpoint, bcrypt password hashing, Form Requests for all input)

Input: TASK-017's schema (`agent_approval_status`, `company_invite_codes` + its `isValid()` helper), Laravel's built-in `MustVerifyEmail` contract + signed verification URLs (no custom verification scheme)

Expected output:
- `POST /api/v1/register/resolve-invite-code` (public, rate-limited) — body `{ invite_code }`, looks up `CompanyInviteCode` by `code` and checks `isValid()` (not expired, not revoked — TASK-017's helper, never reimplemented here), returns `{ company_name }` only on a valid match (404 otherwise, generic message — never confirms/denies which specific codes are "close", and never distinguishes "wrong code" from "expired code" in the response body, to avoid leaking which codes ever existed).
- `POST /api/v1/register` (public, rate-limited) — body `{ invite_code, name, email, phone, password, password_confirmation }`. Validates via a `RegisterRequest` (password complexity via Laravel's `Password::defaults()`, email uniqueness, invite code still valid at submission time — re-checked, not trusted from step 1 since time may have passed). `RegistrationService::registerViaEmail()` creates the `User` (`role = agent`, `company_id` resolved server-side from the matched `CompanyInviteCode` — the client never sends a raw `company_id`, mirroring the existing `StoreClientRequest` "never trust client input for tenant-determining fields" pattern), `registered_via_invite_code_id` set to the matched code's id (reporting only), `agent_approval_status = Pending`, `registered_via = Email`, `email_verified_at = null`, then dispatches Laravel's standard verification email.
- `GET /api/v1/register/verify-email/{id}/{hash}` (Laravel's signed-URL verification route, adapted to return JSON for the SPA rather than a redirect) — on success, sets `email_verified_at` and triggers TASK-020's "notify the Company Admin" step (a small event/hook point this task must leave in place, even though TASK-020 owns the actual Notification class).
- Agent Portal: new public `/register` route + `RegisterView.vue` — step 1 (invite code + "confirm this is your company" display), step 2 (name/email/phone/password form, same required-field-with-* validation UX as the recent Client-form pattern: label asterisks, inline error at the field, focus on the invalid input), a "รอการยืนยันอีเมล" confirmation screen after successful submission.
- Feature tests: valid invite code resolves; invalid code is rejected without leaking which real codes exist; registration creates a `Pending`, unverified user scoped to the correct company; verifying the email sets `email_verified_at`; a second registration attempt with an already-used email is rejected (422); the registration endpoints are rate-limited (test asserts a 429 after exceeding the throttle).

Acceptance Criteria:
  - A visitor with a valid invite code can create an account and receives a verification email (`MAIL_MAILER=log` in dev, per the existing ADR-004 convention — no new mail infra decision needed)
  - An invalid, expired, or revoked invite code is all rejected with the same generic message — the response never reveals which of those three reasons applied
  - The created user cannot log in yet (email unverified AND approval pending) — enforced in TASK-021, but this task's Service must set the correct initial state for that gate to work
  - `company_id` is never accepted directly from the client — only ever resolved server-side from the invite code
  - Registration + invite-code-resolve endpoints are rate-limited (Section 6)
  - `eslint` / `vue-tsc --build` / `vite build` clean (Agent Portal only — this is not an Admin-frontend feature); `php artisan test` passes

Out of scope (this task):
  - Social login (TASK-019)
  - The actual "notify Company Admin" Notification class + Admin approval queue UI (TASK-020) — this task only needs to leave a clean hook (e.g. firing a domain event, or a Service method TASK-020 calls) at the point email verification completes
  - Login-flow blocking messages (TASK-021)
  - CAPTCHA/anti-bot — explicitly decided against for this sprint (ADR-005 decision 5); rate limiting is the only anti-abuse control here

Design notes (flag if wrong):
  - The invite-code-resolve endpoint intentionally returns only the company's display name, nothing else (no ID leakage beyond what's needed to show "is this you?") — flag if more/less confirmation detail is wanted.
  - Password complexity uses Laravel's built-in `Password::min(8)->letters()->numbers()` defaults rather than a custom policy — flag if a stricter/different policy is wanted; this is exactly the kind of unfinalized numeric value BR-7's spirit applies to (config, not a hardcoded assumption), so it should live in a small config value if the human wants it tunable.

Depends on: TASK-017
Blocks: TASK-021 (login gating needs this task's approval-status values to already exist and be reachable)
