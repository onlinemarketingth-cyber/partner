# TASK-044: Agent Commission Summary — Filters + Bank Payout Export

Human-confirmed scope (chat, 2026-07-23). Owner: ag-dev (schema/backend), ag-ui (frontend), ag-qa (tenant-isolation + PDPA-adjacent field tests).

## Problem

`AgentCommissionSummaryView.vue` (TASK-043) shows per-agent paid/pending totals with no
filtering and no way to produce a payout file. Human wants to filter by date range and
payment status, then export the filtered set to actually run bank transfers. No bank
account fields exist on `users` today (confirmed via schema read) — required to make an
export usable at all.

## Human decisions (this session)

1. **Bank account entry**: agent self-service (Profile Settings) AND Admin can edit —
   both paths open.
2. **Export format**: generic CSV/Excel now; bank-specific bulk-transfer templates
   (SCB Business Anywhere, Krungthai Corporate, Bangkok Bank) requested but human was
   not fully certain on this — see Phasing below.
3. **Missing bank info at export time**: export proceeds, flagged rows carry a warning;
   never silently blocks the whole export.

## Phasing (ag-lead proposal — flagged, not a guessed business rule)

**Phase A (this task, TASK-044)**: bank account fields + self-service/Admin edit UI,
date-range + payment-status filters on the summary endpoint, generic CSV export with
warning flags for missing bank info.

**Phase B (separate follow-up task, not started here)**: bank-specific bulk-transfer
file formats (SCB/KTB/BBL). Reason to split: each bank's file spec (column order,
encoding, file extension, required headers) is proprietary and I do not have verified
current specs for any of the three in front of me — per CLAUDE.md Section 8 guardrail
#3 ("never claim a library/method exists without verifying against real docs"), ag-dev
must be hand the actual bank spec document (or a confirmed sample file) before building
that exporter, otherwise the output risks being silently wrong money-movement data.
**Ask to human**: please provide (or point to) the official bulk-transfer file spec for
each bank you want supported, so Phase B can be scoped accurately.

## Scope — Phase A

**1. Backend — bank account fields**
- Migration: `users.bank_name`, `users.bank_account_number`, `users.bank_account_holder_name`
  (all nullable strings).
- Security (CLAUDE.md Section 6, PDPA): `bank_account_number` is sensitive financial
  data — encrypt at rest via Laravel's `encrypted` cast, not plaintext. Never expose
  full number in list views; mask in `UserResource` (e.g. last 4 digits) except on the
  owning agent's own profile view and the new export (which needs the real value).
- Self-service update: extend existing `UserProfileService`/profile endpoint.
- Admin update: extend existing Agent management update endpoint (Company Admin, own
  company only — BR-6).
- Audit log: bank field changes are money-adjacent — must be logged (who/when/old→new),
  per Section 6 Audit Log rule. Mask the account number in the audit log value too.

**2. Backend — filters on `AgentCommissionSummaryService`**
- `date_from` / `date_to` — filters `commission_ledger.created_at` (when the ledger
  entry was recorded, always populated; `paid_at` is null for pending rows so it can't
  be the universal filter axis — flagged here as a design call, adjustable if a
  different date meaning was intended).
- `payment_status` — `pending` / `paid` / omitted = both (existing `PaymentStatus` enum,
  no new statuses added — none were requested).
- All filters additive to existing tenant scoping (BR-6 unchanged).

**3. Backend — CSV export endpoint**
- New route, same auth gate as the summary endpoint (Company Admin own-company /
  Super Admin, optional `?company_id=`).
- Columns: agent name, bank name, bank account number, account holder name, amount to
  pay (pending total, satang → THB at this display/export layer per BR-3), entry count.
- Row-level `missing_bank_info: bool` flag when any of the 3 bank fields is null —
  included as a column so the opened CSV itself shows which rows need follow-up,
  matching decision #3 above (never silently blocks).

**4. Frontend**
- `AgentCommissionSummaryView.vue`: date-range filter (reuse `BuddhistDateInput`),
  payment-status dropdown, "Export CSV" button (streams the new endpoint).
- `ProfileSettingsView.vue` (both apps' self-service profile, if Admin also has one —
  otherwise Agent Portal only): bank account fields, masked display + edit.
- Admin Agent edit form (`AgentManagementView.vue` or equivalent): same 3 fields,
  editable by Company Admin.

## Acceptance Criteria

- [ ] Bank account number stored encrypted at rest, masked in all list/summary
      responses except owner's own profile GET and the CSV export.
- [ ] Self-service AND Admin can both write bank fields; each write creates an audit
      log entry with the account number masked in the logged value.
- [ ] Date-range + payment-status filters work on the summary endpoint; tenant
      isolation unaffected (cross-company access still 403/404).
- [ ] CSV export includes `missing_bank_info` flag per row; export never fails/blocks
      due to missing bank data on some rows.
- [ ] Money values integer satang end-to-end; only divided by 100 at CSV/UI display
      layer (BR-3).
- [ ] `npx vue-tsc --build` + eslint clean; Pest feature tests cover: filter
      correctness, export column shape, masking, audit log write, tenant isolation.
- [ ] ag-qa confirms cross-tenant export attempt (Company Admin passing another
      company's `company_id`) is rejected.

## Out of scope (this task)

- Bank-specific bulk-transfer file formats (SCB/KTB/BBL) — Phase B, blocked on human
  providing verified bank file specs.
- Any new `payment_status` values (e.g. "processing") — not requested.
- Automatically marking ledger rows as paid after export — export is read-only; mark-
  as-paid stays on the existing Commission Management screen's existing flow.
