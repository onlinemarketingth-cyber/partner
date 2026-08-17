# ADR-014: Sales Team Leadership Cockpit (Manager Rollup) + Role-based Sales Views

- **Date:** 2026-07-23
- **Status:** Accepted — human-confirmed 2026-07-23 (research presented, chose "แบบ A: new ทีมขาย page", app-split confirmed). Implemented this session; **backend tests must be run by the human** (sandbox has no PHP). No migration required (read-only).
- **Author:** ag-lead
- **Related:** CLAUDE.md §2, §4.3 (pipeline), §5 (tenant isolation), §6 (single-accent UI), ADR-011/TASK-025 (`manager_id` hierarchy), ADR-012 (Sales IA), ADR-013 (Client File), TASK-038 (Agent Overview), TASK-043 (per-agent commission summary). TASK-050.

> **AMENDED 2026-08-13 — TASK-179 §3.1/§3.6.** Two things in decision 3 below are
> now out of date:
>
> - `closed_deals` is no longer "Complete Payment + Ongoing". It means **reached
>   Complete Payment**, post-sale stages (`delivery` / `service_appointment` /
>   `follow_up`, ADR-026) included, and comes from the single shared
>   `App\Services\Referral\ClosedDealPredicate` that the Admin dashboard uses too.
>   `deals_by_stage` likewise carries the whole eight-case stage vocabulary, not
>   "the 5 §4.3 stages".
> - `GET /sales-team-overview` now also returns `meta.clients_total`, a true
>   company-level `COUNT(DISTINCT client_id)`. The header KPI must use it: summing
>   the per-agent `client_count` double-counts a client referred by two agents,
>   which is a first-class scenario here (TASK-049). `data` is unchanged.

## Context

The human noted that the Admin client/pipeline screens are a flat list, but a company owner needs a **management lens**: team leader → agent group → each agent, showing how many clients each agent has and which pipeline stage they're in. The per-client (individual) lens is for after-sale care — checking what a client bought, through which agent, and how much service they've used. They asked for a research-backed proposal.

Web research (Salesforce/HubSpot sales dashboards, insurance AMS such as Comissio/HawkSoft) converged on **role-based views**: a leadership "cockpit" (production-by-agent, hierarchy/downline screen, pipeline-by-rep, 5–8 KPIs) distinct from an individual rep view (own clients/own pipeline). This matches the human's ask exactly. The codebase already has the `manager_id` upline/downline hierarchy (TASK-025) and per-agent referral data (agent + current_stage), so the aggregate view is a reporting layer over existing data — no schema change.

## Decisions

1. **App split (human-confirmed):** `frontend-admin` = the **company/team-management** lens (this cockpit, for Company Admin / Super Admin). `frontend` (Agent Portal) = the **individual agent** lens (own clients + own pipeline) — already exists (ClientsView, PipelineView), unchanged here.
2. **Chose "แบบ A" — a dedicated new page** (`SalesTeamView`, route `sales-team`, new nav pillar "ทีมขาย") rather than extending `AgentManagementView`. Rationale: a purpose-built leadership cockpit stays a clean role-based dashboard and gives the hierarchy/rollup feel; folding it into the existing "manage agents" page would overload that screen (managing agents + team performance mixed).
3. **Read-only aggregate endpoint** `GET /sales-team-overview` (`SalesTeamOverviewService`): one row per Agent in the company carrying `manager_id` (so the frontend assembles the tree) + KPIs: distinct `client_count`, `deals_by_stage` (the 5 §4.3 stages), `total_deals`, `closed_deals` (Complete Payment + Ongoing), `conversion` %. Reports over existing `referrals` rows only — never writes, never recomputes commission (BR-4 untouched). Company Admin / Super Admin only (Controller `abort_unless`), tenant-isolated by scoping the agent list to `company_id` and keying the referral aggregation off those agent ids.
4. **Drill-down** reuses the existing Client list: `GET /clients?agent_id=<id>` filters to clients that agent sells to (via `referrals.agent_id`, so a client worked by multiple agents appears under each). The cockpit's "ดูลูกค้า" button navigates there; from a client the owner opens the Client File (ADR-013) — that is the **after-sale care lens** (what was bought, through whom; service usage inferred from pipeline meeting counts).
5. **Hierarchy is derived from `manager_id`** on the frontend (recursive tree, cycle-guarded): an agent with direct reports renders as a team leader; agents with no manager (or a manager outside the returned set) are top-level. No new "team leader" role — leadership is emergent from the reporting tree, consistent with TASK-025.

## Consequences

- **Positive:** Owner/leaders get a standard sales-management cockpit (production-by-agent + pipeline-by-rep + hierarchy) without any schema change; individual agents keep their own lens in the Agent Portal. Reuses `manager_id`, existing referral data, and the Client File.
- **Trade-off / deferred:** "How much service used" is currently inferred from pipeline meeting counts (ongoing meeting_number); a dedicated **service-usage/consumption field** was explicitly deferred (BR-7) until the human wants richer tracking than meetings. Sales-amount-per-agent (฿) is not yet on the cockpit (deals + conversion only) — can be added from `commission_ledger` later if wanted. Super Admin cross-company `?company_id` selector is supported by the endpoint but the frontend currently just shows the admin's own company.
- **Operational:** No migration. Run `php artisan test --filter=SalesTeamOverviewTest` to verify (role gate, per-agent aggregation, manager_id hierarchy, tenant isolation, and the `/clients?agent_id=` drill-down filter).

## Out of scope

- Service-usage tracking field (BR-7, deferred).
- Sales-amount (฿) rollup per agent on the cockpit.
- Any Agent-Portal change (the individual lens already exists).
