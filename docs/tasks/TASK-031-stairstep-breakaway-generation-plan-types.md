Task: Stairstep/Breakaway + Generation MLM plan types
Owner: ag-dev
Goal: Build the 2 remaining new MLM plan types together, since both require a new sales-volume-based "rank" concept that doesn't exist anywhere in this codebase yet (distinct from the existing certification-based `CertTier`, which gates BR-1 selling rights, not rank/commission level).
Related: BR-2, BR-4, BR-7, ADR-006 (MLM taxonomy research), ADR-011 Section 3c, CLAUDE.md Section 5
Input:
  - `CommissionPlanType::StairstepBreakaway` and `::Generation` enum cases (added in TASK-027).
  - Existing `users.manager_id` self-referencing chain (reused for Generation's upline traversal — this is the same hierarchy shape as Unilevel, just paid differently).
  - Existing `CertTier` model — for reference only; rank is a new, separate concept, not a rename/extension of CertTier.
Expected output:
  - Migration: `agent_ranks` (company_id, name, volume_threshold, sort_order, created_at/updated_at) — company-configurable rank ladder, thresholds are BR-7 (not invented here).
  - Migration: add nullable `users.current_rank_id` (FK to `agent_ranks`).
  - Scheduled job: recalculate each agent's `current_rank_id` from trailing sales volume on a configurable interval (interval itself is BR-7 — flag, don't assume daily/weekly/monthly).
  - Stairstep/Breakaway service logic: commission rate steps up at each rank (reads from `commission_rules` resolved rate, scaled by rank — exact scaling mechanism must be presented to ag-lead/human as a design question if ADR-006/ADR-011 don't already specify it, rather than invented); a "breakaway" rank marks a downline leg as commission-independent from its former upline going forward.
  - Generation service logic: migration `commission_generation_rules` (company_id, generation_number, rate_type, rate_value — mirrors `commission_override_rules`'s existing shape exactly); traverses `users.manager_id` chain counting breakaway-leg generations, paying each generation's configured override rate. Writes immutable `commission_ledger` rows (BR-4) with new `earned_via` values (e.g. `stairstep_override`, `generation_override` — confirm enum extension needed).
  - Admin UI API: CRUD for `agent_ranks` and `commission_generation_rules` (ag-ui builds the screens in TASK-034).
Acceptance Criteria:
  - Rank thresholds, count of ranks, and recalculation interval are all admin-configurable — none hardcoded (BR-7).
  - Breakaway correctly stops paying the former upline once a downline leg reaches the configured breakaway rank.
  - Generation overrides pay the correct configured rate per generation number, correctly capped at whatever max-generation-depth is configured.
  - Money values are integer satang (BR-3); ledger rows immutable (BR-4).
  - Tenant isolation must pass (cross-tenant access → 403/404) — `agent_ranks` and `commission_generation_rules` company-scoped via TenantScope.
  - Tests cover: rank-up recalculation, a breakaway event stopping former-upline commission, generation override payout across 2+ generations, and cross-tenant access rejected.
Out of scope: Any specific rank-threshold, generation-count, or recalculation-interval default (BR-7 — flag for admin config); UI for the rank ladder / generation table (ag-ui, TASK-034).
Depends on: TASK-027
Blocks: TASK-034
