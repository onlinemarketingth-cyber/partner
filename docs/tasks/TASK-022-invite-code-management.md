Task: Company invite code management (Super Admin)
Owner: ag-dev + ag-ui
Goal: Give a Super Admin a way to create, view, and revoke a company's self-registration invite codes — multiple simultaneous codes per company, each with a mandatory expiry, per ADR-005 decision 6.
Related: ADR-005, TASK-009 (existing `CompanyManagementView.vue`, Phase 7 — this extends that screen rather than adding a new one), TASK-017 (owns the `company_invite_codes` table + `isValid()` helper this task's UI reads/writes)

Input: TASK-017's `company_invite_codes` table

Expected output:
- `GET /api/v1/companies/{company}/invite-codes` (Super Admin only) — lists every code for that company (active, expired, and revoked all shown, distinguished by status — a Super Admin needs to see the whole history, not just what's currently valid).
- `POST /api/v1/companies/{company}/invite-codes` (Super Admin only) — body `{ label?, expires_at }`. `expires_at` is **required** and chosen by the Super Admin each time (never a hardcoded default duration — see TASK-017's design note on this) via a `StoreCompanyInviteCodeRequest`. `CompanyInviteCodeService::create()` generates the actual `code` value server-side (random, opaque — never client-supplied, never derived from the company name/ID) and records `created_by_user_id` from the acting Super Admin.
- `DELETE /api/v1/companies/{company}/invite-codes/{inviteCode}` (Super Admin only) — sets `revoked_at = now()` (soft revoke, not a hard delete — keeps the audit trail per Section 6; the code stops being `isValid()` immediately).
- `frontend-admin`'s `CompanyManagementView.vue`: a new "รหัสเชิญสมัคร Agent" section per company — a table of existing codes (label, status badge: ใช้งานได้ / หมดอายุ / ถูกยกเลิก, expiry date, created-by), a "+ สร้างรหัสใหม่" form (optional label + a `BuddhistDateInput`-driven expiry date picker — reuses the existing component, `type="date"`, forward-looking so its default `yearsBack=0/yearsForward=3` range already fits), a "ยกเลิก" (revoke) action per still-valid row, and a copy-to-clipboard control for the resulting register link (e.g. `https://<agent-portal-host>/register?code=...`).
- Feature tests: only Super Admin can create/list/revoke codes (Company Admin → 403); a company can hold two simultaneously-valid codes at once; revoking a code makes it immediately fail `isValid()` and a subsequent registration attempt using it is rejected; creating a code without `expires_at` is rejected (422).

Acceptance Criteria:
  - Only Super Admin can create/view/revoke invite codes (Company Admin has no access — matches TASK-009's existing company-management boundary)
  - A company can have more than one valid code at the same time
  - Every code has a mandatory expiry chosen at creation time — the endpoint rejects a request with no `expires_at`
  - Revoking a code takes effect immediately (no grace period, per the design already agreed)
  - The generated code value is never derived from anything guessable (sequential ID, company name, etc.)
  - `eslint` / `vue-tsc --build` / `vite build` clean (`frontend-admin`); `php artisan test` passes

Out of scope (this task):
  - Anything about the registration flow itself (TASK-018/019 own that) — this task is purely "where do codes come from, who can create/revoke them, and how many can exist"
  - Automatic reminders before a code expires (e.g. "your code expires in 3 days") — not asked for, flag if wanted

Design notes (flag if wrong):
  - Codes are multi-use until expiry/revocation (see TASK-017's matching design note) — this UI doesn't show a "times used" counter in this first version since usage-counting wasn't asked for; flag if wanted (would read `users.registered_via_invite_code_id` for a simple count).

Depends on: TASK-017
Blocks: none (TASK-018/019 can be tested against a code seeded directly in the dev DB before this task ships a UI for creating one — this only blocks the human-usable "how do I actually get a code" workflow, not the other tasks' own delivery)
