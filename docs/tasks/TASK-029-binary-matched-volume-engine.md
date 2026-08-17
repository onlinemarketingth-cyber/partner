Task: Binary MLM matched-volume calculation engine
Owner: ag-dev
Goal: Implement the actual calculation engine for Binary commission on top of the schema already built in ADR-006 Round 4 (`commission_binary_settings`, `binary_leg_volumes`, `binary_matching_cycles`, `users.binary_leg`), which today is inert — no `CommissionService` code reads or writes it, and the Admin UI marks it "อยู่ระหว่างพัฒนา."
Related: BR-2, BR-4, BR-7, ADR-006 (Binary schema + matched-volume decision), ADR-011 Section 3a, CLAUDE.md Section 5
Input:
  - Existing `commission_binary_settings`, `binary_leg_volumes`, `binary_matching_cycles` tables and `users.binary_leg` column (read these via migration files before starting — do not assume field names without verifying).
  - Existing `commission_ledger` (BR-4) and `CommissionService`.
Expected output:
  - A scheduled job (Laravel scheduled command) that runs each matching cycle: sums each agent's left-leg and right-leg volume for the cycle, computes the matched (lesser-of-two-legs) volume, applies the configured rate from `commission_binary_settings`, and writes one immutable `commission_ledger` row per match (BR-4 — never edited after creation).
  - Unmatched excess volume on the larger leg: apply whatever carry-forward/flush rule is configured in `commission_binary_settings` (this table's exact fields must be read from its migration, not assumed — if the flush-vs-carry rule isn't already a configurable field there, flag this as a gap back to ag-lead rather than inventing the behavior).
  - `CommissionService` integration: expose the resolved plan type check (TASK-027) so Binary-plan-type companies/products route through this engine instead of the standard Unilevel path.
  - Admin UI API: replace the "อยู่ระหว่างพัฒนา" placeholder response with real read endpoints for binary settings + cycle history (ag-ui wires the screen in TASK-034).
Acceptance Criteria:
  - Matched volume is calculated as min(left_leg_volume, right_leg_volume) for the cycle, per ADR-006's already-decided mechanic — not a simplified per-leg-percentage shortcut.
  - Each match produces exactly one immutable `commission_ledger` row with `earned_via = 'binary_match'` (enum value already exists per prior summary).
  - No commission is ever recomputed live from historical ledger rows (BR-4) — reports read the ledger only.
  - Money values are integer satang throughout (BR-3) — no float arithmetic anywhere in the matching calculation.
  - Tenant isolation must pass (cross-tenant access → 403/404).
  - Tests cover: a clean match, an unmatched-excess case, a zero-volume-on-one-leg case, and that the job is idempotent if run twice for the same cycle (no duplicate ledger rows).
Out of scope: Changing the Binary schema itself (already built) or the matched-volume-vs-percentage decision (already made in ADR-006 Round 4) — this task implements, does not redesign.
Depends on: none (schema pre-exists)
Blocks: TASK-034
