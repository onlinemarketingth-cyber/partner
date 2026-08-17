# ADR-012: Sales Workflow IA — Unify Clients + Referral/Pipeline under one "Sales" Pillar

- **Date:** 2026-07-23
- **Status:** Accepted — human-confirmed 2026-07-23 ("ทางเลือก A" for IA, "เฟส 1 ได้" to proceed with Phase 1 only; Phase 2 Kanban explicitly deferred).
- **Author:** ag-lead
- **Related:** CLAUDE.md Section 2 (Client, SWS Referral, Pipeline Stage domain glossary), Section 4.3 (pipeline state machine), ADR-003 (separate Admin frontend), TASK-043 (pillar + subMenus nav pattern), TASK-048.

## Context

In the Admin app (`frontend-admin`), the Customer domain (`ClientManagementView`, route name `client-management`, path `/clients`) and the Referral/Pipeline domain (`ReferralPipelineManagementView`, route name `referral-pipeline-management`, path `/pipeline`) were two **separate top-level nav pillars**. The human flagged that conceptually both belong to one "การขาย" (Sales) area and having them as two adjacent-but-unrelated pillars felt wrong.

A quick review of standard CRM information architecture (HubSpot, Pipedrive, Salesforce) confirmed the intuition: mature CRMs model **Contact/Customer** (a person) and **Deal/Opportunity** (a sale in progress moving through pipeline stages) as two distinct-but-linked objects that live under one shared "Sales"/"CRM" area, cross-linked so you can pivot from a customer to their deals and back. This maps cleanly onto our domain:

| Standard CRM object | Our domain (CLAUDE.md §2) |
|---|---|
| Contact / Customer | **Client** |
| Deal / Opportunity | **SWS Referral** |
| Pipeline (stages) | **Pipeline Stage** (§4.3 state machine) |

One Client can have many Referrals; a Referral always belongs to a Client.

## Decisions

1. **One "การขาย" (Sales) pillar, two sub-menus.** The two former pillars are collapsed into a single pillar (`AdminNavigation.vue`) using the existing TASK-043 `pillar + subMenus` pattern (same mechanism already used by "จัดการตัวแทน"). The pillar `name` points at the first sub-route (`client-management`) so clicking the pillar lands on Clients; `isPillarActive()` already matches ANY sub-route, so both sub-pages highlight the pillar and render row 2. Sub-menu: "ลูกค้า" (`client-management`) and "ดีล / Pipeline" (`referral-pipeline-management`).
2. **Route names and paths are UNCHANGED** (`client-management`/`/clients`, `referral-pipeline-management`/`/pipeline`). No router edits, no broken bookmarks/deep links. This is a nav-grouping + labelling change only.
3. **Bidirectional cross-linking via `?open=<id>` query param.** The Client drawer's referral rows gain a "ดูใน Pipeline" link → navigates to the Pipeline page with `?open=<referralId>`, which auto-opens that referral's stage-history drawer. The Referral drawer gains a "ดูโปรไฟล์ลูกค้า" link → navigates to the Clients page with `?open=<clientId>`, auto-opening that client's drawer. Each target view reads `route.query.open` in `onMounted` after its list loads, opens the matching record's drawer, then `router.replace({ query: {} })` to strip the param so a refresh doesn't re-trigger. No backend change — the Client resource already exposes its referrals (TASK-085).
4. **No schema, no backend, no business-rule change.** Tenant isolation is untouched (both views already scope server-side by role — Company Admin sees own company only). No BR-7 value hardcoded.

## Phasing

- **Phase 1 (this ADR, implemented + live-verified):** nav unification + cross-linking. Low risk, pure frontend.
- **Phase 2 (human-confirmed 2026-07-23 "เฟส 2", implemented + live-verified):** upgraded the Referral page from a flat list to a **Kanban board** — 5 columns following the §4.3 state machine (Complete Registered → Waiting Appointment → Finish 1st Doctor Meeting → Complete Payment → Ongoing Next Meeting), one deal card per referral. **Drag-to-advance respects §4.3 strictly:** because `PipelineService::advance()` only ever moves to the single `allowedNextStages()[0]`, a card may only be dropped on the immediately-next column (the last stage, ongoing_next_meeting, self-loops on itself to increment `meeting_number`). Any other drop (skip forward, any reverse) is rejected client-side with a Thai message and does not hit the API. The per-card "ขั้นถัดไป" button is retained as the always-works path for touch devices (native HTML5 drag is unreliable there) and for the ongoing self-loop increment. No backend/schema change — reuses the existing `POST /referrals/{id}/advance` (no target-stage input, per PipelineService's own docblock). Native HTML5 drag events, no drag library added.

## Consequences

- **Positive:** Nav now matches the standard CRM mental model; Clients and Deals are grouped and navigable in ≤ 3 clicks either direction (DoD §"≤ 3 clicks"). Minimal, reversible change.
- **Neutral:** The pillar `name` reusing `client-management` means the pillar and its first sub-item share a route name — this is the same pattern "จัดการตัวแทน" already uses, so no new precedent.
- **Trade-off accepted:** Phase 1 keeps the Referral list-view as-is; the pipeline visualization win waits for Phase 2. Cross-link uses a query param + `router.replace` rather than a shared store, to keep each view self-contained and refresh-safe.

## Out of scope

- Kanban board (Phase 2).
- Any change to the Agent Portal (`frontend`) — this ADR is Admin-only.
- Editing clients/referrals from these Admin screens (still read/advance-only; creation stays an Agent Portal action).
