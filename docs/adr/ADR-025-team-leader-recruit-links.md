# ADR-025: Team-leader recruit links (`is_team_leader` flag, self-approval, login gating)

- **Date:** 2026-08-05
- **Status:** Accepted — human-confirmed 2026-08-05 (four decisions answered directly). **Implemented 2026-08-05** across TASK-112…117, QA'd in TASK-118, defects fixed in TASK-119. **Backend tests are written but NOT run — the sandbox has no PHP; the human must run `php artisan migrate` then `php artisan test`, and must run the MySQL race procedure in the sprint doc, which the automated suite provably cannot cover.** Frontend `vue-tsc` + `eslint` clean on both apps.

### Measured outcome (added after the sprint)

- The four guarded files (`ClientController`, `ReferralController`, `CommissionLedgerController`, `CommissionLedgerPolicy`) were confirmed untouched, as were the four regression baselines (`RegistrationSchemaTest`, `EmailPasswordRegistrationTest`, `AgentApprovalTest`, `LoginRememberMeTest`) — none were weakened to accommodate the new path.
- **§4 amended:** `DB::transaction()` on the consume path now runs with `attempts: 3`. Holding the link lock across `User::create()` and the Matrix tree walk means a contended second request could exceed `innodb_lock_wait_timeout` and surface as a 500 rather than the 422 §4 promises. The retry is safe precisely because the in-lock re-check re-evaluates `isUsable()` on every attempt, so a retried transaction cannot double-consume. Under sustained contention on a single link all three attempts can still time out — a throughput property of the critical section, not a correctness one.
- **The quota race is verified by reading, not by test.** The suite runs on in-memory SQLite, where Laravel compiles `lockForUpdate()` to an empty string, so serialisation is unprovable there. The existing test proves only that the in-lock check re-reads the row instead of trusting the pre-check. A 6-step MySQL procedure (including an engine check, an injected `sleep(3)` to prove the lock actually blocks, and 20 repetitions) is recorded in the sprint doc and remains outstanding.
- Two tautological tests were found and fixed: the cross-tenant leader-approval test 404'd at route-model binding before the Policy ran, and the admin-created-agent login test built its fixture by hand rather than through `POST /users`. Both would have passed with the guard they exist to protect deleted. `LeaderRecruitScope` now has direct assertions with every other condition forged, plus a positive control.
- **§8's rollout hazard is real and was quantified into SQL, not prose.** The gate deliberately keys on `isSelfRegistered()` rather than `hasVerifiedEmail()`: `UserService::create()` never sets `email_verified_at`, so **every agent an admin has ever created is unverified in the database** and a naive gate would have locked all of them out on deploy. The counting queries are in the sprint doc and must be run before shipping.
- Deferred, flagged not guessed: a misconfigured Matrix company (no `commission_matrix_settings` row) now rejects a recruit-link signup with 422 rather than creating an unplaced agent — parity with the admin path, read literally. Softening it is a business decision.
- Pre-existing and not introduced here: stock Breeze only runs bcrypt when an email matches, so unknown emails answer measurably faster — a timing oracle in the login endpoint.
- **Author:** ag-lead
- **Related:** CLAUDE.md §2, §5 (tenant isolation), §6 (security, audit log), §7, BR-1, BR-6, BR-7. ADR-005 (self-registration + invite codes), ADR-019 (product share links — the token pattern copied here), ADR-024 (team monitor; **this ADR amends two of its decisions**), TASK-017/018/020 (built), TASK-021/022 (were pending; 021 is pulled into this sprint).

## Context

The human asked: *"ผมขอเพิ่มให้หัวหน้าทีมสามารถแชร์ link ผ่านระบบเพื่อให้ลูกทีม Register เพื่อสมัครสมาชิก"* — a leader shares a link, the recruit registers through it, and lands in that leader's team.

Survey of what exists today:

- Registration works and is **invite-code-gated** (`RegistrationService::registerViaEmail()`): the code determines `company_id`, which is never taken from client input. `users.registered_via_invite_code_id` already records which link brought a person in — the precedent for attribution.
- `company_invite_codes` exists (mandatory `expires_at`, `revoked_at`, `created_by_user_id`) but is deliberately **not** `TenantScope`d and is documented as Super-Admin-only (TASK-022, still unbuilt). There is no seeder, so no invite code exists in a fresh install.
- `manager_id` can currently **only** be set on update, through `UserService::assertValidManager()` (same company, no self, no cycle) — and, when the company's plan type is Matrix, that path also triggers `MatrixCommissionService::place()`.
- **`agent_approval_status` gates nothing.** `LoginRequest` is stock Breeze: no approval check, no `hasVerifiedEmail()` check. A self-registered user is `pending` with an unverified email and can log in immediately. TASK-021 specified the gate; it was never built.

## Decisions

1. **"Team leader" becomes an explicit admin-granted flag: `users.is_team_leader` (boolean, default false).** The human chose "agent ที่ admin ระบุว่าเป็นหัวหน้าทีม" over both a cert-based gate and an emergent one.

   **This amends ADR-014 §5 and ADR-024 §1**, which said leadership is purely emergent from the reporting tree. The amendment is deliberately narrow — a **flag on the user, not a fourth role**. `role` stays `agent`, so none of the 97 `isCompanyAdmin()` sites and none of the 34 `isAgent()` narrowing sites change meaning, and the "not an Agent ⇒ an admin" assumption documented in ADR-024 is not disturbed. This is the whole reason a flag was chosen over a `partner` role.

2. **Two distinct capabilities, deliberately not merged.**
   - **Seeing the team monitor** (ADR-024) stays keyed on *having direct reports*. A leader who loses the flag does not lose sight of the team they still manage.
   - **Minting a recruit link and approving recruits** requires `is_team_leader = true`.

   Merging them would mean an admin who forgets the flag silently breaks an existing leader's monitor screen, and an ex-leader keeps recruiting. Keeping them apart makes each failure mode readable.

3. **New table `agent_invite_links`, modelled on `product_share_links` plus the expiry semantics of `company_invite_codes`.** Columns: `company_id`, `agent_id` (the inviter), `token` (`Str::random(64)`, unique), `label` (nullable), `expires_at` (**nullable**), `max_uses` (**nullable**), `used_count` (unsigned, default 0), `revoked_at` (nullable), timestamps. `TenantScope`d; a Policy scopes an agent to their own links.

   The human chose "ตั้งค่าได้ทั้งวันหมดอายุ และจำนวนคน หรือไม่ limit" — hence both limits nullable, where null means unlimited. `isUsable()` is the single source of truth: not revoked, **and** (`expires_at` null or future), **and** (`max_uses` null or `used_count < max_uses`).

4. **Consuming a link is atomic.** `used_count` is incremented inside the same transaction as the `User::create()`, with a `lockForUpdate()` on the link row. Two recruits submitting simultaneously against a `max_uses = 1` link must not both succeed — this is the one place a quota can be defeated by a race, so it is called out here rather than left to the implementer.

5. **The recruit link replaces the company invite code, it does not stack with it.** A link already carries `company_id` (from the inviter), so a recruit arriving via `?ref=<token>` is not asked for an invite code. `POST /register` accepts **either** `invite_code` **or** `ref_token`, never both, and `company_id` continues to come from the server side of that pair — never from the request body.

6. **`manager_id` is set at creation, but only through the existing guards.** Registration must not write `manager_id` straight into `User::create()`: that would bypass `assertValidManager()` and, on a Matrix company, skip `MatrixCommissionService::place()`. The registration path calls the same guarded routine. Cycle risk is nil for a brand-new user, but the Matrix placement is not optional and the guard is the documented contract.

   New column `users.recruited_via_agent_link_id` (nullable FK) mirrors the existing `registered_via_invite_code_id`, so attribution survives even if the leader later changes.

7. **A team leader may approve their own recruits — scoped, and only theirs.** The human chose this over admin-only approval. The scope is exact: `is_team_leader = true` **and** the target's `manager_id = self` **and** the target arrived via one of this leader's links **and** the target is currently `pending`. Anything else → 403. A leader can never reject-then-reassign, never approve someone outside their own tree, and never touch `role`, `company_id` or `manager_id`.

   **This amends ADR-024 §7 ("read-only by construction").** That principle held for *client and sales data* and still does — the exception is narrow, is about the leader's own team roster, and is audit-logged (actor, subject, before → after) per §6. Company Admins keep the full approval queue and can reverse anything a leader did.

   **Residual risk, accepted knowingly:** a leader can now bring people into the company without an admin ever looking. Mitigations: every approval is audited; the Admin approval queue shows leader-approved agents with their approver; the flag is revocable; `max_uses` and `expires_at` let an admin bound a recruiting drive. If this proves too loose in practice, the tightening is a one-line policy change, not a redesign.

8. **TASK-021 login gating is pulled into this sprint.** Without it "pending" is decoration: a recruit logs in and works normally before anyone approves them, which makes decision 7 meaningless and decision 2's "admin still controls entry" false. Gate on login: `email_verified_at = null` → blocked with a resend affordance; `pending` → blocked with a "waiting for approval" state; `rejected` → blocked with the reason, and per ADR-005 decision 7 they may re-apply. Implemented in the auth layer, not in Vue.

## Consequences

- **Positive.** Recruiting becomes self-service for designated leaders, attribution is recorded on the user row, the hierarchy is populated at the moment of registration instead of by a later admin edit, and a real pre-existing hole (unlimited login while pending/unverified) gets closed.
- **Trade-off.** Two ADRs are amended. Both amendments are recorded here rather than by editing history, so the reasoning chain stays readable.
- **Trade-off.** `is_team_leader` is a second thing an admin must remember to set. Accepted at the human's explicit choice; the alternative (emergent) could not express "this person may recruit".
- **Operational.** Two migrations. `php artisan migrate` + `php artisan test` must be run by the human — the sandbox has no PHP. Closing the login gate changes behaviour for **existing** unverified/pending accounts, so the human should check current data before deploying (see the sprint doc).

## Out of scope

- TASK-022 (Super Admin management of company-wide invite codes) — still pending, unchanged by this.
- TASK-019 social login.
- Any leader write access to client, referral, commission or certification data — ADR-024 §7 stands everywhere except the narrow approval carve-out in decision 7.
- Multi-level recruiting incentives / signup bonuses (BR-7 — nobody has specified them).
