# Sprint: Team-leader recruit links — TASK-112 … TASK-118

- **Owner of the sprint:** ag-lead
- **Decision record:** ADR-025 (Accepted, human-confirmed 2026-08-05)
- **Human decisions captured (BR-7):**
  1. Who may mint a link → **an agent the admin has flagged as a team leader** (`users.is_team_leader`), not a cert gate, not emergent from the tree.
  2. Approval of recruits → **the team leader may approve their own**, scoped to their own tree and their own links.
  3. The pre-existing hole (pending/unverified users can log in) → **fix TASK-021 in this sprint**.
  4. Link limits → **both `expires_at` and `max_uses` configurable, both nullable = unlimited**.
- **Standing constraints:** no PHP/composer in the sandbox — the human runs `php artisan migrate` and `php artisan test`. Node/npm available. Money is integer satang (BR-3). No business value hardcoded (BR-7).

Order: **112 → 113 → 114 → 115 → (116 ∥ 117) → 118.**

---

## TASK-112 — Backend: `is_team_leader` flag + `agent_invite_links` schema

**Owner:** ag-dev · **Related:** ADR-025 §1, §2, §3; BR-6

**Expected output:**
1. Migration: `users.is_team_leader` (boolean, default `false`, indexed with `company_id`) and `users.recruited_via_agent_link_id` (nullable FK → `agent_invite_links`, `nullOnDelete`), mirroring the existing `registered_via_invite_code_id`.
2. Migration + model `agent_invite_links`: `company_id`, `agent_id`, `token` (string 64, unique), `label` (nullable), `expires_at` (nullable), `max_uses` (unsigned, nullable), `used_count` (unsigned, default 0), `revoked_at` (nullable), timestamps, index `(company_id, agent_id)`. `TenantScope` in `booted()`. Explicit `$fillable`.
3. `isUsable(): bool` on the model — **the single source of truth**: not revoked **and** (`expires_at` null or future) **and** (`max_uses` null or `used_count < max_uses`). Copy the shape of `CompanyInviteCode::isValid()` and `ProductShareLink::isUsable()`.
4. `is_team_leader` added to `UpdateUserRequest` (Company/Super Admin only) and to `UserResource`. **Not** in `StoreUserRequest` — a brand-new user is never created as a leader.
5. `AgentInviteLinkPolicy` — an agent may only see/revoke their own links; Company/Super Admin see all in their company.
6. Tests: flag defaults false, only an admin can set it, `isUsable()` across every combination (revoked / expired / quota reached / all-null = unlimited), tenant isolation.

**Acceptance criteria:** an Agent cannot set `is_team_leader` on themselves or anyone else; a link with both limits null is usable indefinitely; cross-tenant link access → 403/404.

**Out of scope:** minting, the public route, registration — those are 113/114.

---

## TASK-113 — Backend: mint / list / revoke recruit links

**Owner:** ag-dev · **Related:** ADR-025 §3; §7 layering; the `ProductShareLinkService` precedent

**Expected output:**
1. `App\Services\Registration\AgentInviteLinkService` modelled on `ProductShareLinkService`:
   - `create(User $agent, array $attributes)` — **throws a validation error unless `$agent->is_team_leader`** (same shape as the BR-1 guard in `ProductShareLinkService::create()`, but keyed on the flag per ADR-025 §1). `token = Str::random(64)`. Unlike product share links this is **not** idempotent — a leader may hold several links with different labels/limits at once (ADR-005 decision 6 already established that several valid codes may coexist).
   - `revoke(AgentInviteLink $link)` — soft revoke via `revoked_at`, never a hard delete; attribution on `users.recruited_via_agent_link_id` must survive.
2. `GET|POST /agent-invite-links`, `DELETE /agent-invite-links/{link}` under `auth:sanctum`, authorised by the Policy.
3. `AgentInviteLinkResource` exposing `public_url` = `"{$frontendUrl}/register?ref={$token}"`, plus `used_count`, `max_uses`, `expires_at`, `revoked_at`, `is_usable`, and `label`.
4. Form Request: `label` nullable string, `expires_at` nullable date after now, `max_uses` nullable integer min 1.
5. Tests: non-leader gets a validation error; a leader can hold several links; revoke is soft; an agent cannot list or revoke another agent's link; cross-tenant → 403/404.

**Out of scope:** the public resolve endpoint and registration (TASK-114).

---

## TASK-114 — Backend: register through a recruit link

**Owner:** ag-dev · **Related:** ADR-025 §4, §5, §6 — **the highest-risk task in the sprint**

**Expected output:**
1. `POST /register/resolve-ref-token` (public, `throttle:10,1`) — returns **only** `{ company_name, inviter_name }` and 404s on an unusable token. Mirror `resolveInviteCode`'s deliberate thinness: this endpoint is unauthenticated, so it must not leak the company's agent roster, the link's quota, or how many people have used it.
2. `POST /register` accepts **either** `invite_code` **or** `ref_token`, never both (mutually exclusive validation). `company_id` continues to be derived server-side from whichever was supplied — never from the request body.
3. Registration through a `ref_token`:
   - `company_id` = the link's company.
   - `manager_id` = the link's `agent_id`, **set through the same guarded routine `UserService` uses** — `assertValidManager()` semantics plus the Matrix branch (`MatrixCommissionService::place()`). Do **not** write `manager_id` directly into `User::create()`; ADR-025 §6 explains why.
   - `recruited_via_agent_link_id` = the link id.
   - `agent_approval_status = Pending`, `email_verified_at = null`, email verification sent — unchanged from the existing flow.
4. **Atomic consumption (ADR-025 §4):** wrap the whole thing in a transaction, `lockForUpdate()` the link row, re-check `isUsable()` **inside** the lock, then `increment('used_count')`. Two concurrent recruits against `max_uses = 1` must produce exactly one success and one 422.
5. If the inviter has since been deactivated, had `is_team_leader` revoked, or left the company → the link is unusable. Decide once, in `isUsable()` or in the service, and document which.
6. Tests: happy path sets all four fields; expired / revoked / quota-exhausted → 422; both `invite_code` and `ref_token` supplied → 422; `company_id` in the body is ignored; a Matrix company gets its placement row; **a concurrency test for the quota** (two calls, `max_uses = 1`).

**Acceptance criteria:** no request body value can influence `company_id` or `manager_id`; `used_count` can never exceed `max_uses`.

---

## TASK-115 — Backend: TASK-021 login gating + leader-scoped approval

**Owner:** ag-dev · **Related:** ADR-025 §7, §8; ADR-005 decisions 6–7; CLAUDE.md §6 (audit log)

**Expected output:**
1. **Login gate**, in the auth layer (not Vue). After credentials verify, block and return a distinguishable, non-enumerable response for: `email_verified_at = null`, `agent_approval_status = pending`, `agent_approval_status = rejected` (include the stored reason; ADR-005 decision 7 says they may re-apply). Company Admin / Super Admin are unaffected. Include a resend-verification affordance.
   **Migration/rollout note for the human:** every existing unverified or pending account is locked out the moment this ships. Report the counts before deploying so the human can decide whether to bulk-approve existing rows.
2. **Leader-scoped approval:** extend the approval service with a path allowing an actor where **all** of: `actor->is_team_leader`, `target->manager_id === actor->id`, the target was recruited via one of the actor's links, and `target->agent_approval_status === Pending`. Anything else → 403. A leader may **not** change `role`, `company_id`, `manager_id`, or reject-then-reassign.
3. Every leader approval writes an audit row (actor, subject, before → after) and is visible in the Admin approval queue as leader-approved, with the approver named.
4. Tests: each of the three login-blocked states; an admin logging in normally; a leader approving their own recruit (200); a leader approving someone else's recruit, an already-approved user, a user in another company, and a non-leader attempting any of it (all 403); the audit row exists; a Company Admin can still approve and can reverse a leader's decision.

---

## TASK-116 — Agent Portal UI: share the link, see the recruits

**Owner:** ag-ui · **Related:** ADR-025 §2, §7; ADR-023 tokens; ADR-021 header budget

**Expected output:**
1. On `/my-team`, a **"ชวนเข้าทีม"** action visible only when the API says the caller is a designated leader. Reuse `ShareLinkModal.vue` (QR + copy) — the same component product sharing already uses. The create form exposes label, expiry and max-uses, all optional, with plain-language copy for "leave blank = ไม่จำกัด".
2. A list of the leader's own links: label, usage `used_count / max_uses` (or `ไม่จำกัด`), expiry, status, revoke.
3. A **"รออนุมัติ"** section listing the leader's own pending recruits with an approve action, a confirmation dialog, and a line making clear this admits the person into the company.
4. `RegisterView.vue`: read `?ref=<token>` from the query, call `resolve-ref-token`, and **skip the invite-code step** — show instead "คุณกำลังสมัครเข้าทีมของ <ชื่อหัวหน้าทีม> ที่ <บริษัท>". An invalid/expired token must fall back cleanly to the normal invite-code step with an explanation, not a dead end.
5. After registering, the recruit sees a clear "รอการอนุมัติ" state rather than being dropped at a login screen that now rejects them (TASK-115).

**Acceptance criteria:** 375px correct; theme tokens only; ≥44px targets; `vue-tsc` + `eslint` clean.

---

## TASK-117 — Admin UI: designate team leaders, oversee links

**Owner:** ag-ui · **Related:** ADR-025 §1, §7

**Expected output:**
1. `AgentManagementView.vue`: an `is_team_leader` toggle on the agent edit form, with copy stating exactly what it grants — minting recruit links and approving their own recruits — and that it does **not** change what the agent can see.
2. A read-only view of that agent's recruit links (label, usage, expiry, status) with an admin revoke.
3. The approval queue distinguishes admin-approved from leader-approved and names the approver.

**Acceptance criteria:** no emoji icons, no hardcoded HEX, existing card conventions; `vue-tsc` + `eslint` clean.

---

## TASK-118 — QA

**Owner:** ag-qa · **Related:** CLAUDE.md §9, §5, §6, Guardrail 4

**Test cases:**
1. Non-leader attempts to mint a link → rejected. Flag revoked mid-life → existing links stop working.
2. Quota **race**: two simultaneous registrations against `max_uses = 1` → exactly one success. This is the defect most likely to survive a naive implementation.
3. Expired / revoked / quota-exhausted token → 422; unusable token on `resolve-ref-token` → 404 with no company or inviter leaked.
4. `company_id` and `manager_id` supplied in the register body are ignored.
5. A Matrix-plan company gets its placement row on link registration — parity with the admin path.
6. Login gate: unverified, pending, rejected each blocked and distinguishable; admins unaffected; no user-enumeration difference between "wrong password" and "blocked".
7. Leader approval: own recruit 200; another leader's recruit, an already-approved user, a cross-tenant user, a non-leader actor → all 403. Audit row correct.
8. A leader cannot alter `role`, `company_id` or `manager_id` through any approval path.
9. Cross-tenant: every new endpoint, both directions.
10. Regression: existing `invite_code` registration still works unchanged; `RegistrationSchemaTest` and `EmailPasswordRegistrationTest` still pass.
11. Novice UAT: a leader shares a link and a recruit completes signup on a phone, unaided.

**Definition of Done (§9):** lint + format clean both frontends; tests written and **run by the human**; tenant isolation confirmed; audit log confirmed; ADR-025 updated with the measured outcome; no business value hardcoded; ag-lead review before merge.

---

## Post-QA: what the human must run (added 2026-08-05 after TASK-118 / TASK-119)

### 1. Rollout counts — BEFORE the login gate reaches anyone

Closing the TASK-115 gate locks out every existing unverified self-registrant and every pending account **the moment it ships**. Run these against the target database and bring the results to ag-lead before deploying.

```sql
-- (a) Locked out on EMAIL VERIFICATION (self-registered only)
SELECT COUNT(*) AS blocked_unverified FROM users
WHERE role='agent' AND deleted_at IS NULL AND email_verified_at IS NULL
  AND (registered_via_invite_code_id IS NOT NULL OR recruited_via_agent_link_id IS NOT NULL);

-- (b) Locked out on PENDING APPROVAL
SELECT COUNT(*) AS blocked_pending FROM users
WHERE role='agent' AND deleted_at IS NULL AND agent_approval_status='pending';

-- (c) Locked out on REJECTION
SELECT COUNT(*) AS blocked_rejected FROM users
WHERE role='agent' AND deleted_at IS NULL AND agent_approval_status='rejected';

-- (d) MUST BE 0. A non-zero result contradicts the design — STOP and tell ag-lead.
--     Admin-created agents are unverified in the DB by design and must NOT be gated.
SELECT COUNT(*) AS admin_created_but_pending FROM users
WHERE role='agent' AND deleted_at IS NULL
  AND registered_via_invite_code_id IS NULL AND recruited_via_agent_link_id IS NULL
  AND agent_approval_status <> 'approved';

-- (e) The named list, so the decision is about people rather than a number
SELECT id, first_name, last_name, email, company_id,
       agent_approval_status, email_verified_at, created_at
FROM users
WHERE role='agent' AND deleted_at IS NULL
  AND (email_verified_at IS NULL OR agent_approval_status <> 'approved')
ORDER BY company_id, created_at;
```

### 2. The MySQL quota-race procedure (sprint case 2)

The automated suite **cannot** cover this: it runs on in-memory SQLite, where Laravel compiles `lockForUpdate()` to an empty string. The existing test proves only that the in-lock check re-reads the row.

1. `SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('agent_invite_links','users');` — **both must be InnoDB.** MyISAM would silently ignore the lock and void the whole design.
2. Serve through MAMP's Apache. `php artisan serve` and `php -S` are **single-process** and physically cannot race — either produces a green result that means nothing.
3. Mint a leader + a `max_uses = 1` link.
4. Fire two `POST /register` calls with the same `ref_token` and different emails, backgrounded in one shell so they overlap.
5. Assert **exactly one 201 and one 422**, then `used_count = 1` and exactly one user carrying `recruited_via_agent_link_id`.
6. Repeat ≥ 20 times with a fresh link each round — a race is probabilistic.
7. **Prove the lock actually blocks:** in a scratch copy of `RegistrationService`, insert `sleep(3);` immediately after the `lockForUpdate()->first()`, restart Apache, re-run, and time both responses. The second must take ~3s longer. If both return immediately the lock is not honoured and case 2 **fails** regardless of the status codes. Remove the sleep and re-verify.

### 3. Novice UAT (case 11)

Admin grants `is_team_leader` → leader mints a link with an expiry and a quota, shares the QR → a novice completes signup on a 375px phone unaided → sees "สมัครสำเร็จ — รอการอนุมัติ" → attempts login and gets the `approval_pending` screen → leader approves from `/my-team` → recruit logs in. Record where they hesitated.
