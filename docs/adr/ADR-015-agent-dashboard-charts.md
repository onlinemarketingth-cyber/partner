# ADR-015: Agent Dashboard — Chart-based Overview (ApexCharts) + Metrics Endpoint

- **Date:** 2026-07-23
- **Status:** Accepted — human-confirmed 2026-07-23 (reference dashboards provided; chose ApexCharts + a dedicated backend metrics endpoint). Implemented this session; **backend tests + `npm install` run by the human** (sandbox has no PHP runtime; npm runs in the workspace).
- **Author:** ag-lead
- **Related:** CLAUDE.md §2/§4.3/§5/§6, ADR-003 (separate Admin frontend / dependency policy), TASK-038 (Agent Overview cards), TASK-043 (Commission Summary), TASK-050/051 (Sales Team). TASK-052.

> **AMENDED 2026-08-13 — TASK-179 §3 (human decisions D1–D4).** Decision 2's
> response contract below still describes the *shape* correctly, but four of its
> field MEANINGS were changed because they named a different quantity than they
> measured. Read `docs/tasks/TASK-179-dashboard-metric-definitions.md` §2 and the
> class docblock on `AgentDashboardMetricsService` as the current definitions:
>
> - `totals.sales_paid_satang` is now **money the customer paid** —
>   `SUM(orders.amount_satang)` over paid orders (D1/D2), not the commission
>   ledger's `sale_price_satang_at_time` snapshot. New sibling
>   `totals.closed_deals_without_order` discloses closed deals that contribute
>   zero baht because they have no paid order; they are never estimated.
> - `monthly[].sales_satang` buckets on the **sale date** (the order's `paid_at`,
>   D3), so the sales and commission series are finally on the same time axis.
> - `totals.deals_closed` means **reached Complete Payment**, post-sale stages
>   included (D4) — one shared `ClosedDealPredicate`, not a hardcoded stage list.
> - `totals.agents_total` is **active agents only**; `totals.agents_pending`
>   counts every pending user regardless of role, i.e. exactly what
>   `GET /agent-approvals` lists; `cert_tier_distribution` gives each agent one
>   slice at their highest passed tier.

## Context

The human wants the Admin "Dashboard" (AgentManagementView's overview) reworked from stat cards + lists into a real analytics dashboard of CHARTS, styled like the provided reference dashboards (gradient area chart, bar chart, radial gauge, donut, horizontal-bar breakdown, KPI cards with sparklines, live feed). The repo currently has NO charting library. The overview data exists (agent counts, commission, top performers) but is point-in-time; the charts additionally need time-bucketed series (monthly sales/commission, monthly new agents).

## Decisions

1. **Charting library: ApexCharts via `vue3-apexcharts`** (human-chosen) — first chart dependency in the repo (ADR-003 governs frontend deps; precedent: `pdfjs-dist` added in ADR-008). Chosen over Chart.js because its out-of-the-box visuals (gradient area, radial/gauge, sparkbars, donut) match the reference aesthetic with minimal config. Registered globally as `<apexchart>` in the admin app entry.
2. **Dedicated backend metrics endpoint** `GET /agent-dashboard-metrics` (`AgentDashboardMetricsService` + Controller, Company Admin / Super Admin only, tenant-scoped) — chosen over client-side bucketing so the time series are computed once server-side, are testable, and don't depend on which page of a paginated list the frontend happened to fetch. Read-only aggregation over existing rows; no schema change, never recomputes commission (BR-4 untouched). Returns: totals (agent counts incl. pending, clients, deals, conversion, paid sales, paid+pending commission), a 6-month `monthly` series (paid sales, paid commission, new agents), `deals_by_stage` (§4.3 funnel), `cert_tier_distribution`, `lead_source_distribution`, and `top_agents` (top 5 by paid commission). Money stays integer satang (BR-3). Paid-only money is consistent with TASK-051. Time buckets are computed in PHP from fetched rows (DB-portable across MySQL/SQLite-tests) rather than DB-specific date functions.
3. **Scope: redesign the AgentManagementView "ภาพรวม" (overview) tab into the chart dashboard**; the agent-list tabs (ใช้งาน/ปิดใช้งาน/รออนุมัติ) and all management actions are kept unchanged. The reworked overview reads the new metrics endpoint (plus the existing pending-approvals endpoint for the live feed).

## Chart → data mapping (reference → ours)

- KPI stat cards (+ mini sparkline): agents total/active/pending, paid sales ฿, paid/pending commission, clients, deals closed.
- Gradient AREA chart: monthly paid sales + paid commission.
- BAR chart: new agents per month (and/or deals-closed per month).
- Radial GAUGE: overall conversion %.
- DONUT: cert-tier distribution.
- Horizontal BAR / funnel: deals by pipeline stage.
- Segmented progress bars: lead-source distribution; cert passed vs pending.
- Ranking list / horizontal bar: top agents by commission.
- Live list: pending agent approvals.

## Consequences

- **Positive:** Owner/leaders get an at-a-glance analytics cockpit matching the requested style; all figures come from existing data, tenant-scoped; the time series are server-computed and unit-tested.
- **Trade-off / deferred:** Monthly series fetch paid ledger rows for the last 6 months and bucket in PHP (fine for a single company; if a very large tenant makes this heavy, switch to a grouped DB query with per-driver date formatting later). Paid-only money means brand-new unpaid activity won't move the sales/commission charts (consistent with TASK-051; a paid/all toggle can be added later). "%vs last month" deltas on KPI cards are computed from the same monthly series.
- **Operational:** `npm install` in `frontend-admin` (adds `apexcharts` + `vue3-apexcharts`). Run `php artisan test --filter=AgentDashboardMetricsTest`. No migration.

## Out of scope

- Custom/user-configurable dashboard widgets; export of charts; realtime push (values refresh on page load / manual reload).
- Agent-Portal changes (this is Admin-only).
