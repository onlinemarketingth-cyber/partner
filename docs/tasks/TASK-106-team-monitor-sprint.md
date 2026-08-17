# Sprint: Team Leader Monitor (Agent Portal) — TASK-106 … TASK-110

- **Owner of the sprint:** ag-lead
- **Decision record:** ADR-024 (Accepted, human-confirmed 2026-08-05)
- **Human decisions captured (BR-7):**
  1. Client-data visibility = **all three levels, admin-selectable per company** (`counts_only` / `names` / `full_file`).
  2. Money = **sales + the subordinate's commission + the leader's own override from that subordinate**.
  3. Depth = **the whole chain**, expanded one level at a time.
- **Standing constraints:** sandbox has **no PHP/composer** — the human runs `php artisan migrate` and `php artisan test`. Node/npm are available (`npx vue-tsc`, `npx eslint`). Money is integer satang (BR-3). No business value may be hardcoded (BR-7).

Order: **106 → 107 → (108 ∥ 109) → 110.** 108 and 109 may run in parallel once 107's contract is merged.

---

## TASK-106 — Backend: downline resolution + team visibility config

**Owner:** ag-dev
**Goal:** Give the codebase one trustworthy way to answer "who is below this agent, and how much of their data may this agent see", before any endpoint uses it.
**Related:** ADR-024 §4, §5; BR-6 + CLAUDE.md §5; BR-7; §7 (layered architecture).

**Input:** `users.manager_id` (self-referencing, same-company + cycle validated on write by `UserService::assertValidManager`).

**Expected output:**

1. `App\Enums\TeamVisibilityLevel`: `CountsOnly = 'counts_only'`, `Names = 'names'`, `FullFile = 'full_file'`.
2. Migration + model `team_visibility_settings`: `id`, `company_id` (unique, FK), `client_visibility_level` (string, default `'counts_only'`), `is_enabled` (boolean, default `true`), timestamps. Follow the existing one-settings-table-per-domain pattern (`announcement_settings`, `video_processing_settings`). `$fillable` explicit — never `$guarded = []` (§6).
3. `App\Services\Sales\DownlineService`:
   - `directReports(User $leader): Collection`
   - `subtreeIds(User $leader): Collection` — iterative BFS, one `whereIn('manager_id', …)` per level, visited-set cycle guard, `MAX_DEPTH = 20`, `MAX_NODES = 2000`, every level filtered by `$leader->company_id`.
   - `isInSubtree(User $leader, int $candidateId): bool` — the authorisation primitive used by every endpoint in TASK-107.
   - `resolveLevel(User $leader): TeamVisibilityLevel` — reads the company row; **returns `CountsOnly` when the row is missing or `is_enabled = false`**.
4. `App\Http\Requests\Sales\UpdateTeamVisibilitySettingRequest` — Company/Super Admin only, `client_visibility_level` in the enum values.
5. Admin CRUD endpoints `GET|PUT /team-visibility-settings` (`abort_unless(isSuperAdmin() || isCompanyAdmin(), 403)`), Super Admin may pass `?company_id`. Strip `company_id` from the payload before upsert — see the BR-6 IDOR fix already applied to the other five settings services.

**Acceptance criteria:**
- A leader in company A never receives an id belonging to company B, even if a `manager_id` row somehow crosses tenants.
- A manual cycle inserted directly in the DB terminates `subtreeIds()` instead of hanging.
- A company with no `team_visibility_settings` row resolves to `counts_only`.
- An Agent calling the settings endpoints gets 403.
- Cross-tenant settings access must be impossible. **Amended by ag-lead 2026-08-05 after review:** the literal wording was "→ 403/404", but ag-dev matched the convention of the five existing settings endpoints instead — a Company Admin's `company_id` (query or payload) is *ignored* and the request is scoped to their own row, so no code path ever attempts the other tenant. That is strictly safer than erroring on a value we then have to trust ourselves to have compared correctly, and it keeps the shared BR-6 regression-lock idiom intact. Accepted.
- Unit/feature tests cover: depth > 1, cycle, cap, missing row, disabled row, tenant isolation.

**Out of scope:** any `/me/team` endpoint (TASK-107); touching `ClientController` / `ReferralController` / `CommissionLedgerController` — **they must not appear in this diff at all**.

---

## TASK-107 — Backend: `/me/team` read-only endpoints

**Owner:** ag-dev
**Goal:** Expose the team monitor data, with the visibility level enforced server-side.
**Related:** ADR-024 §2, §3, §6, §8; BR-3; BR-4; §6 (PDPA, audit log).

**Expected output:**

1. `GET /api/v1/me/team` → `{ is_leader, visibility_level, totals, nodes[] }`
   - `nodes[]` = the caller's **direct reports** (or the children of `?parent_id=` when given).
   - Node fields: `agent_id`, `name`, `avatar_url`, `cert_tier`, `has_children`, `client_count`, `deals_by_stage` (the five §4.3 stages), `total_deals`, `closed_deals`, `sales_satang`, `commission_satang`, `my_override_satang`.
   - `totals` rolls up the **entire subtree**, not just the level being shown.
   - `?parent_id=` → **404 unless `DownlineService::isInSubtree()` passes.** This is the feature's primary IDOR surface; it needs its own test.
2. `GET /api/v1/me/team/{user}/clients` — the drill-down, gated by level:
   - `counts_only` → **403** (the endpoint must not exist for that tenant).
   - `names` → client name + current pipeline stage only. No phone, email, national_id, address, documents or health fields — absent from the JSON, not merely hidden.
   - `full_file` → the same shape the subordinate sees, **plus an audit-log write** (actor, subject agent, client id, timestamp).
   - `{user}` must pass `isInSubtree()` → else 404.
3. `sales_satang` / `commission_satang` / `my_override_satang` are read from `commission_ledger` (BR-4 — never recompute, never sum a live calculation). `my_override_satang` = ledger rows where the beneficiary is the caller and the source referral belongs to that subordinate.
4. Extend `GET /me/home` with `direct_reports_count` (integer) so Home can decide whether to render the menu entry without a second request.
5. Dedicated Resources per level (e.g. `TeamNodeResource`, `TeamClientResource`) — never return raw models (§7).

**Acceptance criteria:**
- Every endpoint is GET. No route under `/me/team` accepts a write verb.
- An agent with **no** direct reports gets `is_leader: false` and an empty `nodes[]` — not a 403, and not someone else's data.
- `?parent_id=<id outside my subtree>` → 404. Same for `{user}` on the drill-down.
- Level `names`: assert on the JSON that `phone`, `national_id` and document fields are **absent keys**.
- Level `full_file`: an audit-log row exists after the call.
- Cross-tenant: a leader in company A passing a company B agent id → 404.
- Money fields are integers; no float appears anywhere in the payload.
- Changing the company's level changes the payload without any deploy.

**Out of scope:** UI; export; any write; changing the admin `sales-team` cockpit.

---

## TASK-108 — Admin UI: team visibility setting

**Owner:** ag-ui
**Goal:** Let a Company Admin choose how much of a subordinate's client data a team leader sees.
**Related:** ADR-024 §5; BR-7; the Admin design standards.

**Expected output:** a new section in **ตั้งค่าระบบ** (`frontend-admin/src/views/ThemeSettingsView.vue`), its own card with its own save button, following the pattern of the video-settings section already there:

- Heading: "การมองเห็นข้อมูลทีม (หัวหน้าทีม)"
- Toggle `is_enabled` — off means leaders see no team screen at all.
- Three radio options with plain-language consequences written for a non-technical admin, e.g.:
  - `counts_only` — "เห็นแค่จำนวนและสถานะ ไม่เห็นชื่อลูกค้า (ปลอดภัยที่สุด)"
  - `names` — "เห็นชื่อลูกค้าและสถานะดีล แต่ไม่เห็นเบอร์ เลขบัตร หรือข้อมูลสุขภาพ"
  - `full_file` — "เห็นแฟ้มลูกค้าเต็ม" **with a visible PDPA warning** that this discloses sensitive health data and that every view is recorded in the audit log.
- Reuse the existing `selectedCompanyId` on that page for the Super Admin case.

**Acceptance criteria:**
- Default state for an unconfigured company renders as `counts_only`, matching the backend.
- Saving succeeds and re-loading the page shows the saved value.
- `npx vue-tsc --build --force` and `npx eslint src/` both clean.
- No hardcoded HEX; no emoji icons; follows the existing card/spacing conventions on that page.

**Out of scope:** anything in the Agent Portal.

---

## TASK-109 — Agent Portal UI: `/my-team`

**Owner:** ag-ui
**Goal:** The leader's monitoring screen, mobile-first, read-only.
**Related:** ADR-024 §9; ADR-016; ADR-021 (page-header height budget); ADR-023 (surface/ink tokens — no `text-slate-*`, no `text-white`, no `bg-white`).

**Expected output:**

1. Route `/my-team` → `MyTeamView.vue`.
2. Conditional entry in `HomeView.vue`'s `menuLinks` (icon `users`, label "ทีมของฉัน"), rendered only when `direct_reports_count > 0`.
3. `MyTeamView.vue`:
   - `HeroHeader` — title "ทีมของฉัน", KPIs from `totals` (ลูกทีมทั้งสาย N คน · ยอดขายรวม · ดีลที่ปิดได้).
   - `TabFilterBar` in the header's `tabs` slot: ทั้งหมด / ยังไม่มีดีล / ต้องตาม.
   - A **vertically nested list** (not a horizontal tree): avatar, name, cert tier, then `ลูกค้า N · ดีลเปิด N · ปิด N`, plus the money row. A node with `has_children` gets an expand control that lazy-loads `?parent_id=`.
   - Loading skeleton, empty state (compact + inline, per the existing convention) and error state via the central error normalizer + Toast.
4. Subordinate drill-down: when the API returns 403 for the client list (level `counts_only`), the UI must not show a broken screen — show the counts and a one-line explanation that the company has restricted client details.
5. No edit/advance/grant buttons anywhere on this screen.

**Acceptance criteria:**
- Renders correctly at 375px; header stays inside the ADR-021 budget.
- Only theme tokens for colour — no new `text-slate-*` / `text-white` / `bg-white` under `frontend/src`.
- Every tap target ≥ 44px; expand/collapse has a press state.
- An agent with no reports never sees the menu entry, and typing `/my-team` directly shows a clean "คุณยังไม่มีลูกทีม" state rather than an error.
- `npx vue-tsc --build --force` and `npx eslint src/` both clean.

**Out of scope:** Kanban, CSV export, charts, any write action.

---

## TASK-110 — QA: verification

**Owner:** ag-qa
**Goal:** Prove the boundary holds at the API, not just in the UI.
**Related:** CLAUDE.md §9 Definition of Done; §5; §6; Guardrail 4 (never report a test that was not run).

**Test cases (all must be executed against the real API, not inferred):**

1. **IDOR — sibling.** Leader L1 and leader L2 in the same company. L1 calls `?parent_id=<a node under L2>` → 404.
2. **IDOR — upward.** A subordinate calls `?parent_id=<their own manager>` → 404.
3. **IDOR — cross-tenant.** Leader in company A passes a company B agent id → 404. Repeat on the drill-down.
4. **Non-leader.** A plain agent calls `/me/team` → `is_leader: false`, empty nodes, HTTP 200.
5. **Level enforcement at the API.** For each of the three levels, call the drill-down directly with curl and assert the exact key set. `names` must not contain `phone`, `national_id`, or any document/health key.
6. **Fail-closed.** Delete the company's settings row → drill-down behaves as `counts_only`.
7. **Write attempt.** POST/PUT/PATCH/DELETE against every `/me/team` route → 405/404.
8. **Self-dealing.** A leader attempts `markPaid` on a subordinate's commission row → 403 (regression check on `CommissionLedgerPolicy`).
9. **Audit.** At `full_file`, opening a client writes exactly one audit row with the correct actor and subject.
10. **Money.** Every satang field is an integer; a spot-check of one subordinate's `commission_satang` matches the `commission_ledger` sum for that agent (BR-4 — read, not recomputed).
11. **Cycle / depth.** Insert a cycle directly in the DB → the endpoint returns instead of hanging. A 25-level chain stops at `MAX_DEPTH`.
12. **Regression.** The pre-existing self-scope suites for Client / Referral / CommissionLedger still pass **unchanged** — if any of those three files appears in the sprint diff, kick the PR back to ag-lead.
13. **Novice UAT.** A person who has not seen the feature is asked to find "how many deals my team closed this month" on a phone-sized window, in ≤ 3 taps from Home.

**Definition of Done for the sprint (§9):** lint + format clean both frontends; backend tests written and **run by the human**; tenant isolation confirmed; PDPA levels confirmed at the API; ADR-024 updated with the measured outcome; no business value hardcoded; ag-lead review before merge.
