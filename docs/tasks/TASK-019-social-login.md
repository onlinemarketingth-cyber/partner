Task: Social login registration (Facebook, LINE, Google)
Owner: ag-dev + ag-ui
Goal: Let a person register/sign in as an Agent via Facebook, LINE, or Google, bound to a company via the same invite-code mechanism as TASK-018, per ADR-005 decision 2 (all three providers, not phased).
Related: ADR-005, CLAUDE.md Section 5 (BR-6), Section 6 (never trust client input — `company_id` resolved server-side exactly as in TASK-018, not re-derived differently per provider)

Input: TASK-017's `social_accounts` table, TASK-018's invite-code-resolve endpoint (reused as-is — the "which company" step is identical regardless of signup method), `laravel/socialite` + `socialiteproviders/line` (new Composer dependencies, ADR-005)

Expected output:
- `GET /api/v1/register/social/{provider}/redirect?invite_code=...` — validates the invite code first (reusing TASK-018's resolver), then redirects to the provider via Socialite, carrying the resolved `company_id` inside the OAuth `state` parameter (signed, so it can't be tampered with client-side).
- `GET /api/v1/register/social/{provider}/callback` — on success: look up `social_accounts` by `(provider, provider_user_id)`. If found, this is a returning user (existing behavior, not a new registration — hand off to normal login/session issuance, subject to TASK-021's approval gate like any other login). If not found, create the `User` via `RegistrationService::registerViaSocial()` (`agent_approval_status = Pending`, `registered_via` set to the matching enum case, `email_verified_at` set immediately since the provider already verified it — **except** see the LINE edge case in Design notes) plus a new `social_accounts` row.
- One unified `SocialLoginController` handling all three providers via the `{provider}` route parameter (not three near-duplicate controllers) — provider-specific quirks live in small provider-specific classes/config, not branching logic sprayed through the controller.
- Agent Portal: three "สมัครด้วย Facebook / LINE / Google" buttons on the same `/register` step-2 screen TASK-018 builds (after invite code is confirmed), each just navigating the browser to the redirect endpoint above (no JS SDK popups — server-side OAuth redirect flow only, simpler and avoids each provider's separate JS SDK).
- Feature tests (Socialite's driver is mocked/faked, per Socialite's own testing helpers — no real network calls in tests): a new social login creates a `Pending` user in the correct company; a returning social login (existing `social_accounts` row) does not create a duplicate user; an invite code is still required and still resolved server-side, never trusted from the client.

Acceptance Criteria:
  - All three providers share one controller/route pattern, not three copies
  - `company_id` is resolved exactly once, server-side, from the invite code — never from anything the OAuth callback returns
  - A returning social user (already linked) is not re-created as a duplicate `User`
  - `eslint` / `vue-tsc --build` / `vite build` clean (Agent Portal); `php artisan test` passes using Socialite's fake/mock helpers (no real provider calls in the test suite)

Out of scope (this task):
  - Obtaining the actual Facebook/Google/LINE developer credentials — **blocked on the human**, see ADR-005's "Blocked on" section; this task ships fully structurally complete and testable via mocks, but cannot be exercised against a real provider until credentials exist
  - LINE's `email` permission approval process with LINE Corp — separate, external, may take real calendar time; flagged in ADR-005, not something this task can resolve
  - Account *linking* (an already-logged-in Agent adding a second social provider to their existing account) — this task only covers first-time registration + simple returning-login; linking is a natural but separate future task, flag if wanted sooner

Design notes (flag if wrong):
  - **LINE email fallback:** if a LINE login callback returns no email (permission not granted/approved yet, or the person declined it), the person is redirected to a short "กรอกอีเมลเพื่อยืนยันตัวตน" (enter an email) step before the `User` is created, and that manually-entered email still goes through TASK-018's normal verification-email flow (it's no longer provider-verified once manually typed) — flag if a different fallback (e.g. blocking LINE signup entirely until the permission is approved) is preferred instead.
  - No provider access/refresh tokens are stored anywhere (see TASK-017's `social_accounts` design note) — if a future feature needs to call back into a provider's API on the person's behalf, that would need its own token-storage design at that time, not silently added here.

Depends on: TASK-017, TASK-018 (reuses its invite-code resolver)
Blocks: TASK-021
