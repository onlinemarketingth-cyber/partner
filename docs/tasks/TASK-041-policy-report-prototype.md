# TASK-041 — Policy & Report IA item (มุมที่ 4, prototype)

Owner: ag-dev (backend hardening) / ag-ui (UI polish) / ag-qa (tests)
Status: prototype built by ag-lead 2026-07-22, verified via code review + live browser test (3 of 4 tabs; 4th verified structurally only — see below). Pending human sign-off on the open questions before production-ready.

## Goal

Ship มุมที่ 4 (Policy & Report), the last of the 4-view Admin IA. Unlike views 1-3, this view had no surviving spec anywhere in the repo (confirmed via exhaustive search — no file, ADR, or code comment defined its sub-items). ag-lead proposed a scope grounded in an actual gap analysis of the codebase (what Section 6/BR-7 already require but has no UI) and the human confirmed all 4 proposed sub-items.

## What was built (this pass)

- **4.1 Audit Log Viewer** — exposes the existing (but previously unread) `audit_logs` table. New `AuditLogPolicy`, `AuditLogResource`, `AuditLogController@index` (`GET /audit-logs`, filterable by action/date/company, Company Admin hard-scoped to own company). Also **extended write-coverage**: `audit_logs` previously had exactly one write site in the whole codebase (`UserService::moveToCompany`). Added `AuditLog::create()` calls to `CommissionRuleService::create()`/`update()` (BR-2 rate changes) and `AgentApprovalService::approve()`/`reject()` (permission grants) — both existing Services' method signatures were extended with a `User $actor` param, verified non-breaking (all calls flow through their Controllers, which were updated in the same change).
- **4.2 Cross-company Report** — `PlatformReportService` + `PlatformReportController` (`GET /platform-report`), Super-Admin-only. Per-company agent counts, pending approvals, referral counts, commission paid/pending (satang).
- **4.3 PDPA/Compliance Report** — `ComplianceReportService` + `ComplianceReportController` (`GET /compliance-report`), Company Admin or Super Admin. Consent coverage %, list of clients missing consent (oldest-first).
- **4.4 Config Health Report (BR-7 tracker)** — `ConfigHealthReportService` + `ConfigHealthReportController` (`GET /config-health-report`). Per company: whether `commission_rules`/`gamification_rules` have been actually configured vs. relying on (nonexistent, for commission_rules) or platform defaults (for gamification_rules).
- Frontend: `PolicyReportView.vue`, a single 4-tab Admin view (Platform Report tab hidden entirely for non-Super-Admin), reached via a 9th card on the Admin dashboard home (`AdminHomeView.vue`) — not added to top-nav, consistent with every other prototype view this session.
- No new database tables or migrations — all 4 sub-features read/write existing tables only.

## Verification performed

- Backend: every new file independently read and reviewed by ag-lead (not just the building subagent) — confirmed correct tenant-isolation, correct super-admin gating, and that both extended Service signatures are non-breaking.
- Frontend: `npx vue-tsc --build` + `npx eslint` clean. Every icon name used independently grepped against `Icon.vue` and confirmed to exist.
- Live browser test (Company Admin session, Thai Life): Audit Log tab correctly shows empty state (no commission-rule/agent-approval actions have occurred since this feature shipped) with the mandatory partial-coverage disclosure. Compliance tab confirmed against real data: 12 total clients, 50.0% consent rate, 6 named clients missing consent. Config Health tab confirmed against real data: Thai Life shows 13 commission rules ("กำหนดแล้ว"), 0 gamification overrides ("ยังไม่กำหนด — ใช้ค่า default"), 2 Academy modules, 8 products.
- Platform Report tab (Super-Admin-only): confirmed **structurally** — backend `abort_unless(isSuperAdmin(), 403)` gate reviewed directly, frontend `v-if` hiding reviewed directly, and live-observed as absent from the Company Admin session's tab list (only 3 of 4 tabs rendered, as expected). **Not** live-tested end-to-end as an actual Super Admin — attempted login as the seeded `superadmin@example.test` account failed ("credentials do not match," likely because the dev DB's seed password has since been changed/reseeded); did not force further attempts to avoid disrupting the live session. This is a real, disclosed gap in verification, not a claimed pass.

## Related

BR-2 (commission rate config, now audit-logged), BR-4 (commission ledger, aggregated read-only in 4.2), BR-6 (tenant isolation — Company Admin hard-scoped everywhere), BR-7 (config health tracker is directly BR-7's spirit — surfacing config that's silently defaulted), Section 5 (multi-tenancy), Section 6 (audit trail + PDPA — both directly implemented here), Section 7 (Service-layer discipline for the 2 extended Services).

## Open questions — human should be aware of before relying on this in production

1. **Audit log coverage is still partial.** Only 3 action types are logged (move-company, commission-rule create/update, agent approval). Section 6 asks for coverage of every action touching money/commission/status/certification/permissions — pipeline status changes have their own separate `pipeline_stage_logs` table (not federated into this viewer), and many other mutations (e.g. reward redemption approval from TASK-039, product price promotion changes from TASK-040) are not yet logged here. Expanding coverage is real, ongoing work, not a quick follow-up.
2. **Compliance report has no per-company filter for Super Admin** — it always shows cross-company totals when Super Admin views it. A Super Admin wanting one company's PDPA numbers currently has no filter on this screen (can cross-reference individual clients elsewhere).
3. **Consent tracking itself is a single timestamp field**, not a versioned consent record — no history of what consent text was shown, or of any revocation. If real PDPA compliance reporting is needed later, this is a schema gap, not just a missing report.
4. **Platform Report tab not live-verified as Super Admin** (see Verification section) — structurally correct per code review, but should get one real click-through pass before being trusted for a real cross-company business decision.
5. **No downstream action from Config Health flags** — a company showing "ยังไม่มีกฎคอมมิชชั่นเลย" (no commission rules at all) is surfaced but nothing alerts anyone automatically; it's a manual-check dashboard, not a monitoring/notification feature.

## Acceptance criteria (for the eventual "done" state, not yet all met)

- [ ] Human has reviewed the 5 open questions above
- [ ] Audit log write-coverage expanded per an explicit, agreed priority list (not guessed)
- [ ] Platform Report tab live-verified as an actual Super Admin session
- [ ] Feature tests cover tenant isolation for all 4 new endpoints (cross-company 403/404, Company Admin cannot see another company's audit/compliance/config rows)
- [ ] ag-qa has run a full novice-user UAT pass
- [ ] Reviewed and approved by ag-lead against Section 9 Definition of Done

## Out of scope (this pass)

Notification/alerting on config-health flags, consent versioning/history, federating `pipeline_stage_logs` into the generic audit viewer, per-row audit detail beyond a raw JSON diff view, any write/edit action from this view (entirely read-only reporting).
