Task: Matrix MLM plan type
Owner: ag-dev
Goal: Build a new forced-width×depth Matrix compensation plan type from scratch — the first of the 3 genuinely new MLM plan types this ADR adds (alongside Stairstep/Breakaway and Generation).
Related: BR-2, BR-4, BR-7, ADR-006 (existing MLM taxonomy research), ADR-011 Section 3b, CLAUDE.md Section 5
Input:
  - `CommissionPlanType::Matrix` enum case (added in TASK-027).
  - Existing `users.manager_id` chain (Unilevel) as a reference for how appointment-based hierarchy works today — Matrix placement is deliberately different (auto-placed via spillover, not a deliberate appointment).
Expected output:
  - Migration: `commission_matrix_settings` (company_id, width, depth, spillover_rule, created_at/updated_at) — width/depth/spillover_rule are admin-configurable, no default values hardcoded in application code (BR-7).
  - Migration: `matrix_placements` (user_id, parent_id nullable, position (unsigned int, 0-indexed slot within parent's width), company_id, created_at).
  - Placement service: when a new agent joins under a Matrix-plan-type company/product, place them in the next open slot per the configured `spillover_rule` (breadth-first fill is the common default pattern per ADR-010's MLM research, but the exact rule must come from `commission_matrix_settings`, not be hardcoded).
  - Commission calculation: apply the configured rate (from `commission_rules`, resolved per TASK-028) to each qualifying downline level up to `depth`, writing immutable `commission_ledger` rows (BR-4) with a new `earned_via` value (e.g. `matrix_override` — confirm the enum needs extending).
  - Admin UI API: CRUD for `commission_matrix_settings`, read endpoint for a company's matrix tree (ag-ui builds the visualization in TASK-034).
Acceptance Criteria:
  - Matrix width and depth are never hardcoded — both come from `commission_matrix_settings`, editable per company.
  - Placement respects `width` (no parent slot exceeds configured width) and follows the configured `spillover_rule` consistently.
  - Commission only pays out to `depth` levels, configurable, not fixed.
  - Money values are integer satang (BR-3); no recompute-from-history for reports (BR-4).
  - Tenant isolation must pass (cross-tenant access → 403/404) — `matrix_placements` must be company-scoped via TenantScope like every other business table (Section 5 rule 1).
  - Tests cover: placement fills breadth-first (or whatever configured rule), a spillover case when a parent is full, depth-limited commission payout, and cross-tenant placement access rejected.
Out of scope: UI tree visualization (ag-ui, TASK-034); any specific width/depth/spillover default value (BR-7 — flag for admin config, do not invent).
Depends on: TASK-027
Blocks: TASK-034
