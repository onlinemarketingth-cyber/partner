# ADR-005: Agent Self-Registration & Social Login

- **Date:** 2026-07-13
- **Status:** Accepted (approved by human, 2026-07-13) — all open items resolved, ready for ag-dev/ag-ui
- **Author:** ag-lead

## Context

Today, every Agent account is created directly by a Company Admin (Phase 7, `AgentManagementView.vue` — see TASK-009). The human asked for a second, self-service entry point: a person can register themselves as an Agent from the public Agent Portal, using either an email/username + password, or "Sign up with Facebook / LINE / Gmail". Because this system is multi-tenant (CLAUDE.md Section 5 — BR-6, the highest-priority rule), a self-registering stranger cannot be trusted to declare their own `company_id`, and cannot be trusted to be a legitimate Agent at all — so every self-registration must land in a **pending** state and only becomes a real, working Agent account after a human at that company approves it.

Human decisions confirmed for this ADR (asked directly, not assumed — CLAUDE.md Section 8 rule 2):

1. **Tenant binding at signup:** a per-company **invite link/code** (not a public company dropdown, not single-tenant-only). A registrant must have (or paste) their company's invite code before an account can be created at all.
2. **OAuth scope:** all three providers (Facebook, LINE, Google/Gmail) alongside email/password, in this same effort — not phased.
3. **Approver:** the **Company Admin** of the registrant's own company (matches the existing Section 5 rule 4 pattern — Company Admin manages their own company's data, same boundary as `AgentManagementView.vue` today).
4. **Email verification:** required *in addition to* Admin approval, for the email/password path. (OAuth-provided emails are already provider-verified — see Design notes.)

Follow-up decisions (asked as a second round after this ADR's first draft, all now resolved):

5. **CAPTCHA / anti-bot:** not needed yet — rate limiting alone (Section 6) is the anti-abuse control for this sprint. Revisit if spam/abuse is actually observed.
6. **Invite code lifecycle:** codes **must auto-expire**, and a company **may have several active codes at once** (e.g. one per recruitment campaign/branch) — this replaces the originally-proposed "one column on `companies`" design with a dedicated table (see Schema below).
7. **Rejected-registration re-apply:** a rejected person **may submit a fresh registration** — no permanent lockout.

## Options Considered

**Tenant binding:**
1. Public company dropdown at signup. Rejected — leaks the full list of company names/tenants to anonymous visitors, and lets anyone attempt to join any company by picking it from a list (BR-6 risk surface for zero benefit).
2. Single shared registration form, tenant assigned by domain/subdomain per company. Rejected — no subdomain-per-tenant architecture exists today (CLAUDE.md Section 3 doesn't describe one); would be a much larger, unrelated infra change.
3. A single `registration_invite_code` column directly on `companies` (one code per company, regenerable). Rejected after follow-up decision 6 — the human wants multiple simultaneous codes (per campaign/branch) and auto-expiry, which a single column can't represent.
4. **A dedicated `company_invite_codes` table** — one company can have several rows, each with its own expiry, independently revocable. **Chosen.** Resolving a code to a `company_id` happens server-side before account creation; the code itself grants no data access, only "which tenant does this signup belong to."

**OAuth library:**
1. Hand-rolled OAuth2 client code per provider. Rejected — reinventing a well-solved, security-sensitive wheel (token exchange, state/CSRF protection, provider quirks) is exactly the kind of thing CLAUDE.md Section 8 rule 3 warns against improvising.
2. **`laravel/socialite`** (official, Laravel-maintained) for Facebook + Google, plus **`socialiteproviders/line`** (the de facto community-maintained LINE driver under the well-established `socialiteproviders.com` umbrella, itself built on Socialite's own extension points) for LINE, since Socialite core does not ship a LINE driver. **Chosen** — both packages are added as new Composer dependencies; this is a real architecture decision recorded here since CLAUDE.md Section 3 doesn't currently list them.
3. `laravel/fortify` or `laravel/jetstream` as a full auth scaffold. Rejected — this project already has a working custom Sanctum SPA auth flow (ADR from Phase 0, `AuthController`, `auth.ts` stores in both frontends); Fortify would fight with that rather than extend it, for no real gain here.

## Decision

### Registration flow

1. Agent Portal gets a new **public** (unauthenticated) route, e.g. `/register`. First step: enter the company invite code. A `POST /register/resolve-invite-code` (rate-limited) validates it and returns the company's display name only (no other company data) so the person can confirm "is this my company?" before continuing.
2. Once the code resolves, the person picks a method:
   - **Email/password:** name, email, phone, password → `POST /register` creates a `User` with `role = agent`, `company_id` from the resolved invite code, `email_verified_at = null`, `agent_approval_status = pending` (see schema below). A verification email is sent (Laravel's built-in `MustVerifyEmail` + signed-URL verification link — no custom scheme invented).
   - **Social (Facebook / LINE / Google):** redirect through Socialite to the provider, carrying the resolved `company_id` through OAuth `state`. On callback, if no `social_accounts` row exists yet for that `(provider, provider_user_id)`, create the `User` the same way as the email path, except `email_verified_at` is set immediately (the provider already verified it) — see Design notes for the "provider didn't share an email" edge case.
3. Either path lands the person on a **"รอการยืนยัน/อนุมัติ"** (pending) screen — they cannot log in as a working Agent yet.
4. Once `email_verified_at` is set (email path only — social path already has it) **and** `agent_approval_status = pending`, the relevant Company Admin(s) are notified there's a new registration awaiting approval — reusing the ADR-004 notification pattern (a new `Notification` class, Email channel first, same queued/scheduled infrastructure already built for follow-up reminders — no new infra decision needed here).
5. Company Admin approves or rejects from a new **Pending Agent Approvals** queue in `/frontend-admin`. Approve → `agent_approval_status = approved`, the person can now log in normally (and is still separately gated by BR-1 for selling rights, same as every Agent today — unrelated, unaffected gate). Reject → `agent_approval_status = rejected` (+ optional reason).
6. Login (`AuthController::login`) gains an extra check: block with a clear, distinct message for each of "email not verified yet", "pending Company Admin approval", "registration rejected" — never a generic "invalid credentials" for these cases (that would be confusing, not more secure, since the account genuinely exists).

### Schema (new, TASK-017 owns the actual migrations)

- `users`: add `agent_approval_status` (string enum: `pending` / `approved` / `rejected`; existing rows backfilled to `approved` — Company-Admin-created Agents were already implicitly vetted by the person creating them), `approval_rejection_reason` (nullable text), `registered_via` (string enum: `email` / `facebook` / `line` / `google`, reporting-only), `registered_via_invite_code_id` (nullable FK → `company_invite_codes`, `nullOnDelete` — reporting only: which specific code/campaign brought this Agent in).
- New table `company_invite_codes`: `id`, `company_id` (FK, `cascadeOnDelete`), `code` (unique string), `label` (nullable string — e.g. "สาขาเหนือ ก.ค. 2569", so a Super Admin managing several codes can tell them apart), `expires_at` (**required**, datetime — every code must have an expiry, per decision 6; Super Admin picks the date at creation time, never a hardcoded duration), `revoked_at` (nullable datetime — manual early revocation, independent of `expires_at`), `created_by_user_id` (nullable FK → `users`, `nullOnDelete` — audit trail per Section 6), timestamps. A code is valid only while `revoked_at IS NULL AND expires_at > now()`. Multiple valid rows per company are expected and supported (decision 6), not an edge case.
- New table `social_accounts`: `id`, `user_id` (FK), `provider` (string enum), `provider_user_id` (string), timestamps. No provider access/refresh tokens are stored — nothing here ever calls back to the provider's API after the initial login, so there's nothing worth the security liability of retaining.

## Consequences

- **New Composer dependencies:** `laravel/socialite`, `socialiteproviders/line`, `socialiteproviders/manager`.
- **New "Blocked on human" external credentials** (mirrors ADR-004's SMTP/LINE-OA pattern exactly — cannot be guessed, cannot be tested end-to-end without them):
  - Facebook: App ID + App Secret (Facebook Developers), plus the exact production/staging redirect URI registered there.
  - Google: OAuth Client ID + Client Secret (Google Cloud Console), plus authorized redirect URI.
  - LINE: Channel ID + Channel Secret (LINE Developers), **and** LINE's own approval for the `email` permission scope on that channel — LINE does not grant email access by default, and getting it approved is a separate process with LINE Corp that can take real calendar time. **Flagging this explicitly**: until that's approved, a LINE login may return no email at all (see Design notes below for the fallback).
  - Until real credentials exist, each provider's button can be built and structurally tested (Socialite's redirect can be mocked in feature tests), but no real end-to-end login is possible — same honesty standard as ADR-004's mail/LINE-OA credentials.
- **New public, unauthenticated endpoints** — the invite-code-resolve and register endpoints must be rate-limited (Section 6: "Rate Limiting / Throttling applied to every public endpoint") to resist enumeration/spam.
- Existing `AgentManagementView.vue` (Company-Admin-creates-Agent flow) is unchanged and unaffected — it remains a second, independent way an Agent account can come into existence, and continues to default straight to `approved` (an Admin creating the account *is* the approval).

## Open items — resolved (2026-07-13 follow-up)

All three items raised in the first draft of this ADR were put to the human directly and are now decided (see decisions 5-7 above): no CAPTCHA this sprint, invite codes auto-expire and support multiple simultaneous codes per company, and rejected registrants may re-apply. Nothing left open for this sprint.

## Related

- CLAUDE.md Section 5 (Multi-tenant isolation, BR-6 — highest priority), Section 6 (rate limiting, audit log), Section 8 (never invent business rules / never assume credentials exist)
- ADR-004 (Notification Infrastructure — reused as-is for "Company Admin, you have a pending Agent to review")
- TASK-009 (Admin — Manage Agents; the existing Admin-creates-Agent flow this sits alongside)
- TASK-017 through TASK-021 (implementation, see `/docs/tasks/`)
