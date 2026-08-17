# TASK-046 — Agent Commission Detail Drill-down

## Request

Human (screenshot of `/agent-commission-summary`): "ui คลิ๊กดูรายละเอียด agent และข้อมูลสินค้าที่ขายได้ ลูกค้าที่ขายได้ วิธีคิดค่าคอม" —
clicking an agent row on the Commission Summary page should reveal: which products the agent sold, which clients bought, and how each commission amount was calculated.

## Why no clarifying question was needed

Unlike TASK-044 (bank export format), this does not touch a BR-7 unfinalized value or invent a new business rule. Every field needed already exists as an immutable per-row snapshot on `commission_ledger` (BR-4: rate_type_applied, rate_applied, cert_tier_id_at_time, product_id, earned_via, override_source_agent_id — captured at the moment the rule fired, never recomputed). This is a pure read/display feature, same shape as the existing flat Commission Ledger page (`/commission`), just filtered to one agent and surfaced inline. No design fork worth pausing on.

One deliberate scope decision: **no base sale amount ("10% × 8,900 บาท") is shown**, because the pre-commission base price is not stored on the ledger row (only the final `amount_satang` and the rate snapshot are). Back-deriving a base price from `amount_satang` and `rate_applied` would be an invented number that could be wrong for `fixed_satang` rows, override/binary/matrix/generation rows, or if a price promotion (TASK-040) was active at sale time — CLAUDE.md §8 guardrail #2 ("never assume numbers"). Instead the panel shows the stored calculation snapshot itself: rate type + rate value + cert tier + `earned_via` (direct/renewal/override/binary_match/matrix_override/stairstep_override/generation_override/promotion_bonus) + override source agent when applicable. This answers "how was it calculated" using only real, already-persisted data.

## Backend changes

- `CommissionLedgerResource` — add `earned_via` (string) and `override_source_agent` (`whenLoaded`, id+name) to the response. Both already exist on the model/DB; simply weren't exposed before.
- `CommissionLedgerController::index()` — add optional query filters, mirroring `AgentCommissionSummaryController`'s existing inline-`validate()` pattern:
  - `agent_id` — only honored for Company Admin/Super Admin. An Agent's own forced self-filter (`where('agent_id', $request->user()->id)`) is unconditional and always wins, so a non-Agent-supplied `agent_id` can never let an Agent see someone else's row.
  - `date_from` / `date_to` / `payment_status` — same filters already on the summary endpoint, so the drill-down list can match whatever range/status the Admin currently has applied on the parent page.
- No new route needed (`GET /commission-ledger` already exists). No IDOR risk: `CommissionLedger` carries `TenantScope`, so a Company Admin passing a foreign-company `agent_id` simply gets zero rows (their query is already narrowed to their own `company_id` before the `agent_id` filter is even applied).
- Feature tests: `agent_id` filter scoping (own-company agent returns rows, cross-company agent_id returns empty, Agent role's own-agent_id override query param is ignored), filters compose correctly with date/status, `earned_via`/`override_source_agent` present in the response.

## Frontend changes

- `AgentCommissionSummaryView.vue` — add a second per-row toggle button ("ดูรายละเอียด") alongside the existing "บัญชีธนาคาร" one (TASK-045's pattern), independent open/close state. On open: `GET /commission-ledger?agent_id=<id>` + whatever `date_from`/`date_to`/`payment_status` are currently set in the page's own filters, so the drill-down total matches the collapsed row's paid/pending figures.
- Row rendering reuses `CommissionManagementView.vue`'s existing `formatRate()`/`formatSatang()`/`formatDate()` conventions for visual consistency. Adds a small Thai label map for `earned_via` (pure UI translation of an existing enum, not a new business rule) and, when `override_source_agent` is present, a "จาก <name>" note.
- Default pagination (existing endpoint already `->paginate()`s) is left as-is; if `meta.total` exceeds the returned page, the panel shows "แสดง X จาก Y รายการทั้งหมด" rather than silently truncating.

## Acceptance criteria

- Clicking "ดูรายละเอียด" on an agent row shows a list of that agent's commission_ledger entries: client name (or "—" for binary_match rows), product name (or "—"), cert tier, rate, amount, payment status, date, earned_via label, override source when applicable.
- Company Admin only ever sees entries within their own company; passing a foreign-company `agent_id` returns an empty list, never another company's data.
- Agent role is never able to use this endpoint to see another agent's entries, even if `agent_id` is manipulated client-side.
- No invented/derived numbers — every value shown comes directly from a stored column.
- vue-tsc + eslint pass; new backend feature tests pass (user to run locally, no PHP runtime in this environment).
