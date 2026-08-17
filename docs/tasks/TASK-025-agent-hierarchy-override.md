Task: Agent Hierarchy + Override Commission
Owner: ag-dev + ag-ui
Goal: Let an Agent report to a Manager (multi-level — a Manager can themselves report to another Manager), and pay each Manager in the chain an override commission, at a rate based on the Manager's own cert tier, whenever someone in their downline earns a direct sale commission.
Related: ADR-006 (Commission Configuration Model), BR-1, BR-2, BR-4, BR-6 (tenant isolation — a manager relationship must never cross companies), Section 5 (multi-tenancy)

Input: `users` (existing), `commission_rules`/`CommissionService` (existing direct-commission calculation, this task hooks into it rather than replacing it), `cert_tiers` (existing)

Expected output:
- Migration: `users.manager_id` (nullable, self-referencing FK → `users.id`, `nullOnDelete` — losing a manager should never delete/break the report, just leave them un-managed until reassigned). No new role/enum — a "Unit Manager"/"Branch Manager" is just an Agent whose own `manager_id` points further up the chain; multi-level falls out of this for free, no level cap.
- Validation (Service layer, not just a DB constraint): `manager_id` must belong to the same `company_id` (BR-6) and must never create a cycle (A manages B manages A) — reject with a clear error if it would.
- Migration: new `commission_override_rules` table — `company_id`, `manager_cert_tier_id` (FK → `cert_tiers`), `rate_type`, `rate_value`, `effective_from`, `effective_to` (nullable) — same shape as `commission_rules`, but keyed by the **manager's own** cert tier (ADR-006 decision), not the selling agent's.
- `CommissionService` change: after creating the normal direct-sale `commission_ledger` row, walk `manager_id` upward from the selling agent. For each manager found (any depth), look up `commission_override_rules` by that manager's current cert tier; if a rule exists, create one **additional**, separate, immutable `commission_ledger` row crediting that manager (never modifies the original direct-sale row — BR-4). Stop walking when `manager_id` is null (top of chain) — no artificial depth limit.
- `commission_ledger` gains `earned_via = 'override'` (see TASK-024, shared column) and a nullable `override_source_agent_id` (who the override was earned *from*) so a manager's ledger/reports can separate "my own sales" from "my team's overrides".
- `frontend-admin` "Manage Agents": a "หัวหน้า" (manager) dropdown per agent row (Company Admin only, same company's agents/managers as options, self excluded, prevents picking a manager that would create a cycle client-side too — server is still the real guard).
- Feature tests: a 3-level chain (Agent → Unit Manager → Branch Manager) correctly creates 3 ledger rows (1 direct + 2 overrides) from one sale, each at the correct manager's own cert-tier rate; a manager with no configured override rate for their tier creates no override row (never a $0 row, never an error); assigning a manager across companies is rejected (BR-6); assigning a manager that would create a cycle is rejected; removing a manager (`manager_id = null`) doesn't affect historical ledger rows already created (BR-4).

Acceptance Criteria:
  - A company that never assigns any manager relationship sees zero behavior change (no override rows ever created)
  - Override commission is always a new, separate, immutable ledger row (BR-4) — the original direct-sale row is never touched
  - Override rate is always read from `commission_override_rules` keyed by the manager's own tier (BR-2/BR-7) — nothing hardcoded
  - Cross-company manager assignment is impossible (BR-6) — test proves 403/422, not just UI hiding
  - `php artisan test` passes; `eslint`/`vue-tsc`/`vite build` clean (frontend-admin)

Out of scope (this task):
  - A visual org chart / team tree view — not asked for, flag if wanted later
  - Bulk reassignment tooling (e.g. moving an entire team to a new manager at once) — one-at-a-time via the dropdown is enough for this task
  - Any XP/gamification interaction with hierarchy (e.g. team leaderboard) — BR-5 gamification is unaffected by this task

Depends on: none new (existing `users`/`cert_tiers`/`commission_ledger`/`CommissionService`)
Blocks: none
