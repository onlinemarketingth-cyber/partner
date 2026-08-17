# TASK-179 — Admin dashboard: say what the numbers actually mean

- **Owner:** ag-lead (spec) → ag-dev (phase 1) → ag-ui (phase 2) → ag-qa (phase 3)
- **Date:** 2026-08-13
- **Origin:** human asked whether the Admin agent dashboard shows real data. Full audit run
  2026-08-13; findings referenced below as F-1 … F-17.
- **Related:** BR-3, BR-4, BR-6, BR-7, ADR-017 (orders), ADR-026 (pipeline templates), CLAUDE.md §4.3

---

## 1. The finding this task exists to fix

**Nothing on these screens is mocked.** Every figure traces to a real query over real tables, no
endpoint returns a stub, and every money figure reads the immutable ledger rather than
recomputing it (BR-4 respected throughout). The audit found no fabricated data.

The problem is the other kind: **real numbers under labels that describe something else.** A
fake number gets spotted. A real number with the wrong name gets acted on.

## 2. Human decisions (2026-08-13) — these are the definitions, do not reinterpret

**D1 — "ยอดขาย" means money the CUSTOMER paid.** Not commission the company has disbursed to
agents. The two are different axes and the current card conflates them (F-1).

**D2 — Source of that money: paid orders only, and disclose what could not be counted.**
`SUM(orders.amount_satang) WHERE status = paid`. A closed deal with no order contributes **zero
baht and is never estimated**. The screen must say so — see §3.2. Rejected alternatives, recorded
so they are not revisited: the ledger price snapshot (missing for pre-TASK-047 rows, and *absent
entirely* for any deal closed while no commission rule was configured, because
`CommissionService::recordForReferral()` returns null and writes no row); and joining
`products.price_satang` live (a price change would silently rewrite last year's revenue).

**D3 — The 6-month chart's X axis is the SALE date**, i.e. when the customer paid — not when the
company disbursed commission (F-6).

**D4 — "ขายได้แล้ว" / closed = the referral has REACHED Complete Payment**, including every
post-sale stage after it (จัดส่ง / นัดใช้บริการ / ติดตามผล). Advancing a paid deal must never
reduce the close rate (F-3).

## 3. Phase 1 — backend (ag-dev)

Files: `backend/app/Services/Sales/AgentDashboardMetricsService.php`,
`backend/app/Services/Sales/AgentSalesAggregateService.php`,
`backend/app/Services/Commission/AgentCommissionSummaryService.php`,
`backend/app/Http/Controllers/Api/V1/AgentApprovalController.php` (read only — see §3.4).

### 3.1 A single "has this deal closed?" predicate — **write it once**

D4 needs "at or past Complete Payment" **per the referral's own template** (ADR-026), and both
`AgentDashboardMetricsService::75` and `AgentSalesAggregateService::158` currently answer it with
their own hardcoded two-stage list. That is the two-predicates-that-drift pattern this codebase
has produced five times since ADR-026. **One predicate. Both callers use it.**

`PipelineService::hasReachedStage()` is correct but is per-referral — do not call it in a loop
inside an aggregate.

**Proposed SQL-expressible predicate, to be VERIFIED before you rely on it:**

> A referral has closed iff `current_stage = 'complete_payment'` **OR** a `pipeline_stage_logs`
> row exists for it with `to_stage = 'complete_payment'`.

This is attractive because reaching payment is an *event*, so it needs no template resolution and
no stage ordering at all. **Verify first** that every already-closed referral actually has that
log row — TASK-134a backfilled templates and seeders may have written `current_stage` directly.
Run the check, report the numbers, and if the log is not reliable say so and propose the
alternative rather than shipping a predicate that silently under-counts history.

### 3.2 `sales_*` — rebuild on orders (D1/D2)

- Replace the ledger-derived `sales_paid_satang` with `SUM(orders.amount_satang)` over
  `status = paid`, tenant-scoped (BR-6).
- Add a companion field, e.g. `closed_deals_without_order`: the count of closed referrals (§3.1)
  with no paid order. **This number is the whole point of D2** — it is what stops the total from
  quietly under-reporting. Do not omit it and do not fold it into anything.
- Keep integer satang end to end (BR-3). No `round()`, no float, no `amount_baht` sibling.

### 3.3 Monthly series — bucket on the sale date (D3)

Bucket on the paid order's `paid_at`, not on `commission_ledger.paid_at`. Commission remains a
separate series on the same chart; state in a comment that the two series are now on the same
time axis and why that was not previously true.

### 3.4 `agents_pending` vs the approvals list (F-7)

Do not change the endpoint. The defect is in the frontend (§4.3). But **do** fix the KPI/list
mismatch at its root: `agents_pending` counts `role = agent` only, while `/agent-approvals`
filters on `agent_approval_status` with no role filter, so a pending Company Admin appears in one
and not the other. Make the KPI count exactly what the list contains, and say which it is.

### 3.5 `agents_total` (F-8)

The dashboard includes soft-deleted agents; `SalesTeamOverviewService` does not; both labels read
`ตัวแทนทั้งหมด`. Pick one meaning — **active agents, excluding deactivated** — and make both
services agree. If a deactivated count is wanted it is a separate, separately-labelled field.

### 3.6 `clients_total` on the sales-team screen (F-15)

`SUM(per-agent COUNT(DISTINCT client_id))` double-counts a client referred by two agents, which
is a first-class scenario here (TASK-049 exists for it). Return a true company-level
`COUNT(DISTINCT client_id)` alongside the per-agent figures, and let the header KPI use it
instead of summing the cards.

### 3.7 `AgentCommissionSummaryService` under a status filter (F-10)

Lines ~156-159 force the excluded bucket to literal `0`, so filtering by "จ่ายแล้ว" renders
"รอจ่ายรวม 0 บาท" — indistinguishable from "we owe our agents nothing". Either compute both
buckets regardless of the filter, or return `null` for the excluded one so the UI can render
"ไม่ได้แสดง" rather than a number nobody measured. **A zero that was never measured is the exact
failure this task exists to remove — do not ship another one.**

### 3.8 Cert tier donut (F-5)

An agent holding Basic *and* Intermediate is counted in both slices, so the percentages are not
shares of the workforce. Either return the agent's **highest** passed tier (one agent, one slice)
or add an explicit `agents_uncertified` and let §4 label it as a partition of certified agents
only. State which you chose in the docblock.

### 3.9 Tests

Feature tests for each definition above, and specifically:

- a closed deal with **no** order → contributes 0 baht **and** increments
  `closed_deals_without_order`
- a referral advanced past Complete Payment into a post-sale stage → **still counted as closed**
  (this is D4, and it is the assertion that would have caught F-3)
- a sale in month A whose commission is disbursed in month B → lands in **month A**
- cross-tenant: company A's dashboard never sees company B's orders or referrals (BR-6)
- the commission summary under `payment_status=paid` does not report a fabricated 0 for pending

## 4. Phase 2 — frontend (ag-ui)

Files: `frontend-admin/src/views/AgentDashboardOverview.vue`,
`frontend-admin/src/views/salesTeam.ts`, `frontend-admin/src/views/SalesTeamCard.vue`,
`frontend-admin/src/views/SalesTeamView.vue`, `frontend-admin/src/views/AgentCommissionSummaryView.vue`.

### 4.1 Un-hardcode the pipeline stages (F-4, BR-7) — the important one

`AgentDashboardOverview.vue:283` holds `STAGE_LABELS = ['ลงทะเบียน','รอนัด','พบแพทย์','จ่ายแล้ว','ต่อเนื่อง']`
and reads five named keys; `salesTeam.ts:147-171` holds a second copy; `SalesTeamNode.vue` a
third. The backend already returns **all eight** stages.

- Render **whatever stages the server sends**, in the order it sends them. No fixed-length array,
  no named key access, no five-element TypeScript interface.
- The funnel's bars must sum to the ดีลทั้งหมด KPI. Today they cannot.
- Thai *labels* may stay in a map (code stays English per §7) — it is the fixed **ordering and
  length** that violates BR-7, not the translations.
- Delete the third copy with its dead files (§4.5).

### 4.2 Labels that now have to match the definitions

- The sales card: label it as customer money and drop "(จ่ายแล้ว)", which reads as commission
  payout. Put `closed_deals_without_order` on the card as a plain sentence when it is > 0 —
  e.g. "อีก N ดีลปิดแล้วแต่ยังไม่มีคำสั่งซื้อ ยอดนี้จึงยังไม่รวม". **When it is 0, say nothing** —
  a permanent caveat trains people to ignore it.
- Stop pairing a money value from one source with a deal count from another in one card (F-2).
- The close-rate gauge caption must describe D4, not "ดีลปิด ÷ ดีลทั้งหมด" over two stages.
- "Top ตัวแทน" sums paid commission only — say so in the label.

### 4.3 Pending approvals (F-7)

`AgentDashboardOverview.vue:112` takes page 1 of a paginated endpoint and renders
`pendingAgents.length` as the badge — capped at 15, contradicting the KPI beside it.
`AgentManagementView.vue:224` already solves this with `fetchAllPages()`. Use the same helper, or
show the server's `meta.total` in the badge and label the list "แสดง N จาก M". Do not invent a
third approach.

### 4.4 Empty and error states (F-13, F-14)

- The `v-else-if="!metrics"` empty state at `:341` is **unreachable** — `metrics` is always
  assigned on success. A brand-new company therefore sees a flat 6-month chart and a confident
  radial gauge reading **"0%"** under "อัตราปิดการขาย". That reads as a measured result. Gate the
  chart, the new-agents bar and the gauge on "is there anything to measure yet", and say
  "ยังไม่มีข้อมูล" when there is not.
- The `/agent-approvals` catch at `:114-116` swallows everything, so a 403 or 500 renders as the
  green "ไม่มีตัวแทนรออนุมัติ" — **a failure displayed as good news.** Show an error state.

### 4.5 Delete dead code

`SalesTeamGroup.vue` (self-marked DEPRECATED) and `SalesTeamNode.vue` are imported nowhere. They
hold the third copy of the hardcoded stage list. Per the human's standing rule — *"ไม่ใช้แล้วก็
เคลียร์ออก ไม่ต้องเผื่อไว้"* — delete them. Verify zero importers first.

### 4.6 Not in this task

Chart colours are raw hex (`:86-89`) rather than the ADR-018 theme variables, so charts ignore a
tenant's palette. Real, cosmetic, and a different concern — separate task.

## 5. Phase 3 — verification (ag-qa)

- [ ] Every §3 definition has a test that fails if the definition is reverted (mutation-check it
      and report the observed failure count, as on TASK-176/177)
- [ ] No hardcoded stage list survives anywhere in `frontend-admin/src` — grep and show the output
- [ ] A zero-data company renders "ยังไม่มีข้อมูล", not 0% and a flat chart
- [ ] Cross-tenant isolation on every changed endpoint (BR-6)
- [ ] `vue-tsc`, `eslint src`, `npm run build`, `vitest` clean; backend suites green

## 6. Out of scope (Phases 3–4 of the plan, not this task)

Surfacing `agent_targets` read-back, the leaderboard, Academy progress, and KPIs on the Admin
landing page. Those are new features; this task is about the existing numbers being true.

## 7. Definition of Done

CLAUDE.md §9, plus: **no number on these screens has a label that describes a different
quantity**, no business value is hardcoded (BR-7), and no figure that was excluded by a filter or
by missing data is rendered as `0`.
