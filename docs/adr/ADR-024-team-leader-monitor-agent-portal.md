# ADR-024: Team Leader Monitor in the Agent Portal (downline read scope)

- **Date:** 2026-08-05
- **Status:** Accepted — human-confirmed 2026-08-05. Human chose option **C** ("a separate read-only team screen in the Agent Portal") over option A (widening the existing self-scoped screens) and option B (letting an Agent into `frontend-admin`). **Implemented 2026-08-05** across TASK-106..109, QA'd in TASK-110, defects fixed in TASK-111. **Backend tests are written but NOT run — the sandbox has no PHP; the human must run `php artisan migrate` then `php artisan test`.** Frontend `vue-tsc` + `eslint` are clean on both apps.

### Measured outcome (added after the sprint)

- The three protected controllers (`ClientController`, `ReferralController`, `CommissionLedgerController`) and `CommissionLedgerPolicy` were confirmed untouched — the central premise of choosing C over A held in practice.
- QA found one HIGH defect: `is_enabled = false` disabled only the client drill-down while `/me/team` still returned every subordinate's earnings, i.e. a privacy switch that lied about what it did. Ruled and fixed in TASK-111 — the switch is now a real kill switch at all three touchpoints (`/me/team`, the drill-down, and `direct_reports_count` on `/me/home`), with the `CountsOnly` fallback retained as defence in depth.
- A latent 500 was found while fixing it: Eloquent's enum cast calls `BackedEnum::from()`, so the documented `tryFrom(...) ?? default()` fail-closed arm in `resolveLevel()` was unreachable — a single hand-edited `client_visibility_level` row would have thrown for the whole tenant instead of degrading to `counts_only`. The settings service now reads the raw attribute.
- §5's boundary was tightened during QA: at `full_file` a client's referral rows could disclose the identity of agents **outside** the caller's subtree (a shared client), contradicting the 404 we return when the same leader asks about a sibling's node directly. Referral rows are now kept but the out-of-subtree agent/co-agent identity is blanked.
- Deferred to a human decision (flagged, not guessed): all money figures are **paid-only**, matching the admin cockpit — so a closed-but-unpaid deal shows `closed_deals: 1` with `sales_satang: 0`. Changing it would have to change for both consumers.
- Process gap noted by QA: the project is **not under version control**, so the "three files untouched" check could only be made by file mtime, and CLAUDE.md §7's "feature branches + PR review by ag-lead" is currently unenforceable.
- **Author:** ag-lead
- **Related:** CLAUDE.md §2 (glossary), §4 BR-4 (commission ledger is immutable / never recompute), BR-6 + §5 (tenant isolation), BR-7 (unfinalized values are admin config), §6 (PDPA, audit log). ADR-003 (two-app split), ADR-011 / TASK-025 (`manager_id` chain), ADR-014 (admin-side Sales Team cockpit), ADR-016 (Agent Portal = personal app), ADR-013 (Client File).

## Context

A team leader (หัวหน้าทีม) currently has **no way to see their team's work**. Everything hierarchy-facing lives in `frontend-admin` behind `abort_unless(isSuperAdmin() || isCompanyAdmin(), 403)`, and an Agent is actively logged out of that app by the router guard (`frontend-admin/src/router/index.ts:214`). Meanwhile the Agent Portal has zero hierarchy awareness: `ClientController:38`, `ReferralController:56` and `CommissionLedgerController:40` all hard-narrow to `agent_id = self`.

Three options were analysed for the human:

- **A — widen the existing Agent Portal screens** to "self + downline". Best UX (filters, Kanban and search come for free) but it puts the new query shape inside the three endpoints that **every agent uses every day**; one mistake in the `whereIn` leaks other agents' clients to everyone, and the whole existing tenant-isolation test suite has to be reopened.
- **B — let the Agent into `frontend-admin`, restricted to their own downline, with the Academy / commission / settings menus hidden.** Rejected. Hiding a menu in Vue hides a button, not an endpoint. More importantly, as long as the user stays `role = agent`, **B requires every line of backend work that C requires**, and then adds: relaxing the admin router guard (which currently destroys the shared Sanctum session, ADR-003), hiding menus, and making a desktop-first UI usable on a phone. Strictly more cost and more risk for no capability C lacks.
- **C — a new, separate, read-only team screen in the Agent Portal.** Chosen.

A fourth path (a new `partner` / `unit_admin` role) was costed and **deferred**: the codebase carries an implicit assumption that "not an Agent ⇒ an admin" — 34 `isAgent()` sites narrow the query and have no `else`, so adding a role value without auditing all of them would hand the new role the whole company's client list, including health data. That audit (97 `isCompanyAdmin()` sites, 37 Policies, 29 `abort_unless`) is a project of its own and is not needed for a monitoring screen.

## Decisions

1. **No new role.** A team leader remains `role = 'agent'`. Leadership is emergent from `users.manager_id`, reaffirming ADR-014 decision 5 and TASK-025. "Is this user a leader" is answered server-side by `directReports()->exists()` on the authenticated user — never from a flag sent by the client.

2. **The existing self-scoped endpoints are not touched.** `ClientController`, `ReferralController` and `CommissionLedgerController` keep exactly one Agent query shape (`= self`). The downline shape lives only in new, purpose-built, **read-only** endpoints under `/me/team`. This is the entire security argument for choosing C over A: the blast radius of a bug is one new screen, not the daily workflow of every agent in every tenant.

3. **Depth: the full chain, expanded one level at a time.** `GET /me/team` returns the leader's direct reports; `?parent_id=` fetches the children of a node the caller has already been shown. `parent_id` **must be validated as a member of the caller's own subtree** — this is the IDOR surface of the whole feature. Header KPIs roll up the **entire** subtree, so the leader sees the true total without expanding every node.

4. **Downline resolution is a single Service** (`App\Services\Sales\DownlineService`), iterative breadth-first over `manager_id`, one `whereIn` per level, cycle-guarded by a visited set, capped by `MAX_DEPTH` and `MAX_NODES` constants, and always scoped to the caller's `company_id` (BR-6). Iterative BFS was chosen over a recursive CTE for readability and because tenant scoping stays in Eloquent; if a tenant's tree ever makes this slow, swapping the internals for a CTE is a local change behind the same method signature.

5. **Client-data visibility is company config, not a hardcoded rule (BR-7).** The human chose "all three levels, selectable by the admin". New table `team_visibility_settings` with `client_visibility_level ∈ {counts_only, names, full_file}`:
   - `counts_only` — counts and pipeline stage totals only, no client identity. **This is the default for any company that has not configured it**, deliberately: an unconfigured tenant must fail closed, not open.
   - `names` — client name + pipeline stage. No phone, no national_id, no health data, no documents.
   - `full_file` — the full Client File as the subordinate sees it.
   **The level is enforced in the API Resource, never in the Vue component.** A field the level does not permit must not be present in the JSON at all.

6. **Money visibility: sales + the subordinate's commission + the leader's own override from that subordinate.** All three read from `commission_ledger` — never recomputed (BR-4). The leader's own override rows are theirs already; the subordinate's commission rows are the new disclosure, and the human accepted that trade-off knowingly (a leader can infer a team member's earnings). Amounts stay integer satang end to end (BR-3), divided by 100 only at render.

7. **Read-only by construction.** No POST/PUT/PATCH/DELETE anywhere under `/me/team`. Advancing a subordinate's pipeline stage, editing their client, granting certification and marking commission paid all remain outside this feature. `CommissionLedgerPolicy::markPaid` stays Company/Super Admin only — a leader must never be able to settle their own or their team's payout.

8. **PDPA audit.** When `client_visibility_level = full_file` and a leader opens a subordinate's client, the read is written to the audit log (who, whose client, when). Levels `counts_only` and `names` are not logged per-view — there is no personal data disclosed at `counts_only`, and logging every name-list render would drown the log without adding accountability.

9. **UI placement: the "เมนูทั้งหมด" grid on Home** (`HomeView.vue:179` `menuLinks`), shown conditionally when the caller has at least one direct report — **not** a sixth bottom-nav tab. The bar has five slots on a 375px screen and Thai labels stop being legible below 11px; a tab that appears for some users and not others also breaks the fixed-position muscle memory that TASK-079 established. `/me/home` gains `direct_reports_count` so Home can make this decision without a second request.

## Consequences

- **Positive.** A leader gets a monitoring view on the phone they already carry, inside the app they already use. No admin-app guard is relaxed; no role enum changes; no existing endpoint changes behaviour; the existing tenant-isolation suite stays valid. The PDPA posture is a per-company decision the admin owns, with the safe value as the default.
- **Trade-off.** The team screen starts plainer than the admin cockpit — no Kanban, no saved filters, no CSV export. That is the accepted price of not touching the daily-use endpoints. Features can be lifted across one at a time later.
- **Trade-off.** Deep trees cost one query per expanded level. Acceptable because expansion is lazy and user-driven; the subtree rollup for header KPIs is the one place that walks the whole tree, and it is capped.
- **Operational.** One migration (`team_visibility_settings`). Backend tests must be run by the human (`php artisan test`) — the sandbox has no PHP.

## Out of scope

- Any write action by a leader on a subordinate's data.
- A `partner` / `unit_admin` role (costed above, deferred — needs the `isAgent()` audit first).
- CSV export, charts, or a team leaderboard (leaderboard is on hold by standing human instruction).
- Changing the admin-side `sales-team` cockpit (ADR-014) — it stays as the company-wide lens.
