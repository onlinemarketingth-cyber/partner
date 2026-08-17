Task: Unified plan-type UI cohesion + full regression QA
Owner: ag-ui, ag-qa
Goal: Bring every new plan-type's admin configuration screen into one cohesive experience (company default + product override, category/product commission rules, Binary/Matrix/Stairstep-Breakaway/Generation settings), and run full regression + security QA across the entire multi-model surface before rollout.
Related: BR-1..BR-7, ADR-011 (all sections), CLAUDE.md Section 5 (tenant isolation), Section 6 (security), Section 9 (Definition of Done)
Input:
  - Completed TASK-027 through TASK-033 (all backend + affiliate frontend work).
Expected output (ag-ui):
  - Admin (`/frontend-admin`) screen: set a company's default plan type; per-product override control (with a clear "inherit from company" state, not just a blank/null-looking field).
  - Admin screen: `commission_rules` editor extended to support category-level and company-default rows alongside the existing product-level rows, with resolution order visible to the admin (so it's clear which row will actually apply).
  - Admin screens for `commission_binary_settings`, `commission_matrix_settings` (+ tree visualization), `agent_ranks` ladder, `commission_generation_rules`, `affiliate_attribution_settings` — each following this project's existing design-system conventions (Icon.vue, no emoji, consistent card/table patterns already used elsewhere in `/frontend-admin`).
Expected output (ag-qa):
  - Full regression pass across Sales/LMS/Gamification (existing suites) to confirm no plan-type change broke existing Unilevel/Binary-as-was behavior.
  - New test cases: cross-tenant access attempts on every new endpoint from TASK-027 through TASK-032 (expect 403/404 per Section 5).
  - Security pass on the 2 new public/unauthenticated endpoints from TASK-032 specifically — rate-limit verification, input-validation fuzzing, bot-mitigation bypass attempts, token-enumeration attempts.
  - Load/edge-case testing on the Binary/Matrix/Generation calculation engines (e.g. a Matrix parent completely full, a Generation chain with a cycle/loop guard, a Stairstep breakaway mid-cycle).
Acceptance Criteria:
  - Every screen in this task follows Section 9's Definition of Done in full (lint, tenant-isolation tests, security checklist, satang storage, responsive, ≤3-click core workflows, OpenAPI updated).
  - No BR-7 value is hardcoded anywhere across the whole multi-model surface — ag-lead spot-checks this specifically before approving.
  - ag-qa signs off with an explicit tenant-isolation + security confirmation (per this project's own Checklist Before Closing a Feature).
  - All regression suites green, not just the new ones.
Out of scope: Any new backend logic — this task is UI cohesion + QA only, building strictly on TASK-027..033's already-built APIs.
Depends on: TASK-028, TASK-029, TASK-030, TASK-031, TASK-033
Blocks: TASK-035
