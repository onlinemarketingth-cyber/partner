Task: Registration domain schema (approval status, invite codes, social accounts)
Owner: ag-dev
Goal: Lay the DB/model foundation ADR-005 depends on, before any registration endpoint or UI exists — approval/verification state on `User`, multiple auto-expiring per-company invite codes, and a table to record linked social identities.
Related: ADR-005 (decisions 1, 6), CLAUDE.md Section 5 (multi-tenant isolation — BR-6), Section 6 (audit trail — who created an invite code), Section 7 (layered architecture, `$fillable` explicit, no `$guarded = []`)

Input: `users` table (existing), `companies` table (existing)

Expected output:
- `App\Enums\AgentApprovalStatus`: `Pending` / `Approved` / `Rejected`, same style as `ClientStatus`/`ClientActivityType`.
- `App\Enums\RegistrationChannel`: `Email` / `Facebook` / `Line` / `Google` — reporting-only, never used for authorization decisions.
- Migration: `users` gains `agent_approval_status` (string, default `'approved'` at the DB level so this migration itself doesn't retroactively lock out any existing account), `approval_rejection_reason` (nullable text), `registered_via` (string, default `'email'`), `registered_via_invite_code_id` (nullable FK → `company_invite_codes`, `nullOnDelete` — reporting only, which campaign/code brought this Agent in). A follow-up data step in the *same* migration explicitly sets every pre-existing row to `agent_approval_status = 'approved'`, `registered_via = 'email'` (documented as intentional backfill, not a guess — see Design notes).
- Migration: new table `company_invite_codes` — `id`, `company_id` (FK → companies, `cascadeOnDelete`), `code` (unique string), `label` (nullable string), `expires_at` (**required** datetime — no code may be created without one, per ADR-005 decision 6), `revoked_at` (nullable datetime), `created_by_user_id` (nullable FK → users, `nullOnDelete`), timestamps.
- Migration: new table `social_accounts` — `id`, `user_id` (FK → users, `cascadeOnDelete`), `provider` (string), `provider_user_id` (string), timestamps, unique composite index on `(provider, provider_user_id)`.
- `App\Models\CompanyInviteCode` — `belongsTo(Company::class)`, `belongsTo(User::class, 'created_by_user_id')`, a `isValid(): bool` accessor/method encapsulating `revoked_at === null && expires_at->isFuture()` so every later task (TASK-018's resolver, TASK-022's listing UI) checks validity the same single way, never reimplementing the condition.
- `App\Models\SocialAccount` — `TenantScope` is NOT applied (a social identity isn't itself company data; it's resolved through its `user`, which is already tenant-scoped) — `belongsTo(User::class)`.
- `User` model: add `agent_approval_status` (cast to `AgentApprovalStatus`), `approval_rejection_reason`, `registered_via` (cast to `RegistrationChannel`), `registered_via_invite_code_id` to `$fillable`/casts; add `socialAccounts(): HasMany`, `registeredViaInviteCode(): BelongsTo`.
- `Company` model: add `inviteCodes(): HasMany`.
- Factories: `UserFactory` gains a `pendingApproval()` state helper; `SocialAccountFactory` (new); `CompanyInviteCodeFactory` (new — defaults `expires_at` to 30 days out, a factory convenience default only, never a hardcoded production expiry rule).
- Feature tests: existing users still pass `agent_approval_status = approved` after migration; a factory-created pending user has the expected default; `social_accounts` unique constraint rejects a duplicate `(provider, provider_user_id)` pair; `CompanyInviteCode::isValid()` correctly returns false once `expires_at` is past or `revoked_at` is set, even if the other condition is fine; a company can have two simultaneously-valid codes with different labels.

Acceptance Criteria:
  - Running the new migration against the current dev database does not change any existing user's ability to log in (all backfilled to `approved`)
  - `agent_approval_status` rejects any value outside the three enum cases at the validation layer (used by later tasks, not yet exercised by an endpoint in this task)
  - A `company_invite_codes` row cannot be created without an `expires_at` (DB-level `NOT NULL`, not just app-level validation)
  - A company can hold more than one simultaneously-valid code (no unique-per-company constraint beyond the `code` value itself being globally unique)
  - Tenant isolation unaffected — `social_accounts` has no direct `company_id` column by design (see Design notes) and is never queried except through an already-tenant-scoped `User`; `company_invite_codes` is naturally scoped through `company_id` like every other business table (Section 5 rule 1)
  - `eslint`/`vue-tsc`/`vite build` not applicable (backend-only); `php artisan test` passes

Out of scope (this task):
  - Any registration/OAuth endpoint, any UI, any notification — purely schema + model + enum foundation for TASK-018/019/020/021 to build on
  - Invite code *generation/management* UI (Super Admin creating/revoking codes from `CompanyManagementView.vue`) — that's TASK-022; this task only builds the table + model `isValid()` helper it depends on

Design notes (flag if wrong):
  - `social_accounts` deliberately has no `company_id` column — a social identity always belongs to exactly one `User`, and that `User` is already tenant-scoped; duplicating `company_id` here would be redundant data that could drift out of sync. Flag if a direct tenant-scoped query on `social_accounts` turns out to be needed later (none is anticipated).
  - Existing rows are backfilled to `agent_approval_status = 'approved'` as a deliberate, explicit migration step (not a default masking the real intent) — this is the correct behavior per ADR-005 ("Admin creating the account *is* the approval"), not a guess.
  - Invite codes are treated as **multi-use** until they expire/are revoked (any number of people can register with the same valid code) — this wasn't asked explicitly, only "can a company have several codes" was confirmed. Flag if single-use-per-code is actually wanted instead; that would add a `used_at`/redemption-count field here.

Depends on: none (can start immediately)
Blocks: TASK-018, TASK-019, TASK-020, TASK-021, TASK-022
