Task: Admin — Clients, Referral & Pipeline, Commission oversight screens
Owner: ag-ui — executed directly by ag-lead, no separate ag-ui session running yet
Goal: Close out the last "unbuilt UI over an already-working API" gap flagged in SETUP.md — company-wide read views for Clients and Referral & Pipeline, plus the one write action the Admin app is actually allowed to do on Commission Ledger (mark paid).
Related: Section 5 rule 4 (Company Admin sees all records within own company — exercised here for Client/Referral, both of which already returned the full company list server-side for non-Agent roles since Phase 3/4, this task only builds the missing frontend), BR-3 (money display, satang→THB only at this layer), BR-4 (Commission Ledger immutability — markPaid is the one allowed mutation, already Policy-gated since Phase 5), PDPA/Section 6 (Client health_notes and documents, same gating as the Agent Portal's existing view)

Input: `ClientController`/`ReferralController`/`CommissionLedgerController` and their Policies/Resources — **all pre-existing, zero backend changes this task**. Every endpoint used here (`GET /clients`, `GET /clients/{id}/documents`, `GET /client-documents/{id}/download`, `GET /referrals`, `POST /referrals/{id}/advance`, `GET /referrals/{id}/stage-logs`, `GET /commission-ledger`, `POST /commission-ledger/{id}/mark-paid`) already existed and was already tested/reviewed in Phases 3-5; this task is pure `frontend-admin` UI work, ported from the Agent Portal's equivalent views with an added agent-name column (Admin needs to see WHOSE record this is, since Admin sees the whole company, not just "their own").

Expected output:
- `frontend-admin/src/views/ClientManagementView.vue` — company-wide client list (read-only), detail drawer with documents + download (no upload/create — that stays an Agent-initiated sales action, deliberately not duplicated here).
- `frontend-admin/src/views/ReferralPipelineManagementView.vue` — company-wide referral list with stage tabs, "ขั้นถัดไป" advance action (same no-target-stage-input design as the Agent Portal — Admin can advance on an agent's behalf, already Policy-permitted since Phase 4), audit-trail drawer.
- `frontend-admin/src/views/CommissionManagementView.vue` — company-wide ledger list with pending/paid tabs and the "จ่ายแล้ว" (mark paid) button — the one write action, already restricted server-side to Company Admin/Super Admin (an Agent could never reach this screen's action even if they somehow loaded the page, since `frontend-admin` requires a session but the Policy is the real gate).
- `frontend-admin/src/api/client.ts` — added the `download()` helper (ported verbatim from `frontend`'s client, needed for the Clients screen's document downloads — this app never had it before since no prior Admin screen touched files).
- `AdminHomeView.vue` — 3 more real cards (ลูกค้า / Referral & Pipeline / Commission), router gained 3 more routes.

Acceptance Criteria:
  - A Company Admin sees every client/referral/commission entry in their own company on these 3 screens, not just their own — verified against the pre-existing server-side scoping (no client-side filtering was added or needed)
  - Client health_notes and documents are visible in the same PDPA-gated way as the Agent Portal (no new exposure introduced)
  - Advancing a referral's pipeline stage from the Admin screen behaves identically to the Agent Portal (no client-supplied target stage, backend always computes the one allowed next stage)
  - "จ่ายแล้ว" only appears on pending entries, and calls the existing `markPaid` endpoint — no new mutation logic invented client-side
  - `eslint`, `vue-tsc --build`, `vite build` all clean for `frontend-admin` (confirmed this session)

Verification status:
  - No backend files were touched this task, so no new backend structural review was performed — every endpoint consumed here already went through its own Phase 3/4/5 structural review and (per those phases' own docs) is pending the human's `php artisan test` run for those suites, same as before. This task adds no new backend risk.
  - Frontend: `eslint . --cache` clean, `vue-tsc --build` clean (exit 0), `vite build` clean (exit 0, `dist/` produced including all 3 new view chunks) — all three actually run and confirmed in this session, in a throwaway `/tmp` copy (same sandbox `@rolldown/binding-linux-arm64-gnu` optional-dependency workaround as every prior frontend verification pass this session).

Out of scope (future tasks):
  - Admin-initiated Client creation or document upload on an agent's behalf — not requested, and would need its own scope discussion (StoreClientRequest already supports an Admin specifying `referring_agent_id`, but the UI for picking which agent doesn't exist here, deliberately, to avoid inventing a new flow).
  - Bulk commission "mark paid" (pay all pending for an agent this month) — same out-of-scope note as TASK-007, still one row at a time.
  - Any filtering/search/export on these 3 new screens beyond the existing stage/status tabs.

Design notes (not in CLAUDE.md, decided here — flag if wrong):
  - The Clients screen is deliberately read-only rather than mirroring every Agent Portal capability — client creation is a sales-initiated action that conceptually belongs to the agent doing the selling, not an Admin acting on their behalf, so no create/upload form was added to avoid inventing a new business flow without confirmation.
