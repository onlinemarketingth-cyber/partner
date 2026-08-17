# ADR-011: Unified Multi-Model Agent Architecture (Affiliate + Insurance + MLM + PRM, Simultaneous)

- **Date:** 2026-07-22
- **Status:** Accepted — scope locked in by the human's explicit answers below. Every rate/threshold/window value inside this scope remains **undecided (BR-7)** and is flagged, not invented.
- **Author:** ag-lead
- **Depends on:** ADR-006 (Commission Configuration Model — decided), ADR-010 (standards research — options only, superseded by the decision below)

## Context

Following ADR-010's research, the human decided Sync Vision Agent must **cover all four models at once** — Affiliate, Insurance-agent, Direct-selling/MLM, and PRM/SaaS-partner — rather than choose one. The model in effect must be settable **per company, with a per-product override**, and commission must be configurable **at both product-category and individual-product level**. This ADR is the resulting architecture and sprint breakdown; it does not re-decide anything ADR-006 already locked in (Binary's matched-volume mechanic, the ledger, renewal, split) — it extends that foundation.

### Locked-in decisions (human's answers, verbatim intent)

| Question | Decision |
|---|---|
| Binding granularity | **Company sets a default plan type; individual Products can override it.** Not company-only, not product-only. |
| Affiliate depth | **Full mode** — trackable links with an attribution window, not just a label. |
| MLM plan-type coverage | **Binary (finish the existing inert engine) + add Matrix + Stairstep/Breakaway + Generation** — all 4 non-Unilevel types from ADR-006's taxonomy, not a subset. |
| Commission scoping | **Both product-category-level and individual-product-level**, with product-specific overriding category-default. |

Nothing beyond this table was decided by the human. Every number below (attribution-window days, binary cycle cadence, matrix width/depth defaults, stairstep rank thresholds, affiliate rates) is explicitly **out of scope for ag-lead to invent** — each must ship as admin-editable config/seed data per BR-7, with sensible-but-changeable seed defaults chosen by whoever builds the admin UI for it, never hardcoded into a Service.

## 1. Plan-Type Binding: Company Default + Product Override

**Schema change:**
- `companies.commission_plan_type` (existing column) stays as the tenant's default.
- Add `products.commission_plan_type` — nullable, same enum type. `NULL` = inherit the company default.
- `CommissionPlanType` enum (`backend/app/Enums/CommissionPlanType.php`, currently only `Unilevel`/`Binary`) gains 4 new cases: `Matrix`, `StairstepBreakaway`, `Generation`, `Affiliate`.

**Resolution rule (new step in `CommissionService`):**
```
$effectivePlanType = $product->commission_plan_type ?? $company->commission_plan_type;
```
This is a pure read-time resolution, not a data migration — existing rows are unaffected (`products.commission_plan_type` defaults `NULL`, i.e. 100% backward-compatible with every company currently on Unilevel/Binary).

**Consequence:** a single company can now sell one product under Affiliate terms and another under MLM terms simultaneously, which is the concrete requirement behind "โดยผู้รูปแบบกับบริษัทหรือสินค้า" (model bound at company-or-product level).

## 2. Commission-Rate Scoping: Category + Product

Distinct from plan-**type** (above), this is plan-**rate** scoping — how `commission_rules` resolves a rate. Currently `commission_rules.product_id` is `NOT NULL`, so a company-wide or category-wide default is only achievable indirectly (loop-apply to every product in TASK-023's UI), not a true default row — this exact gap was flagged and deferred in ADR-006 as "Option B."

**Schema change:**
- Make `commission_rules.product_id` **nullable**.
- Add `commission_rules.product_category_id` — nullable, FK to `product_categories` (naming mirrors `products.category_id`'s existing convention, not a new `product_category_id`-vs-`category_id` inconsistency... note: the FK column itself should be named `product_category_id` on `commission_rules` since it's a foreign table reference, matching Laravel convention for FK column naming — this differs from `products.category_id` only because that column lives ON the categorized table itself; both point at `product_categories.id`).
- Add a DB-level constraint (checked in the Form Request, not just relied on as an app convention): at most one of `product_id` / `product_category_id` may be set per row; both `NULL` = company-wide default for that cert tier.

**Resolution order (most-specific wins), new step in `CommissionService`:**
1. Row matching this exact `product_id` (existing behavior, unchanged).
2. Row matching this product's `category_id` via `commission_rules.product_category_id`.
3. Row with both `product_id` and `product_category_id` NULL (company-wide default for the cert tier).
4. No match → existing "no commission rule configured" error path (unchanged).

This is additive and backward-compatible: every existing `commission_rules` row already has `product_id` set, so it keeps matching at step 1 exactly as today.

## 3. MLM Plan-Type Coverage

### 3a. Binary — finish the existing inert engine
Schema already exists (`commission_binary_settings`, `binary_leg_volumes`, `binary_matching_cycles`, `users.binary_leg` — ADR-006 Round 4). No new tables. Build the matched-volume-per-cycle scheduled job per ADR-006's already-decided mechanic (not re-decided here) and wire `CommissionService` to read/write it, replacing the Admin UI's "อยู่ระหว่างพัฒนา" placeholder.

### 3b. Matrix — new
Forced-width×depth placement (e.g. 3-wide × unlimited-deep, or N-wide × N-deep — **width and depth are BR-7, company-configurable, not fixed by ag-lead**), with spillover when a leg fills. New schema:
- `commission_matrix_settings` (company_id, width, depth, spillover_rule) — mirrors `commission_binary_settings`'s shape.
- `matrix_placements` (user_id, parent_id, position, company_id) — the placement tree itself, since Matrix placement is structurally distinct from the existing `users.manager_id` self-referencing chain (Matrix is auto-placed by the system's spillover rule; Unilevel/`manager_id` is a deliberate appointment — this is a real structural difference, not just a labeling one).

### 3c. Stairstep/Breakaway + Generation — new, grouped (one sprint)
Both require a **sales-volume-based rank** concept, distinct from the existing certification-based `CertTier` — this is the one genuinely new domain concept this ADR introduces.
- `agent_ranks` (company_id, name, volume_threshold, sort_order) — company-configurable rank ladder (BR-7: thresholds not invented here).
- `users.current_rank_id` (nullable FK) — recalculated periodically (job) from trailing sales volume; exact recalculation window is BR-7.
- Stairstep/Breakaway: commission % steps up at each rank; a "breakaway" rank makes a downline leg commission-independent of its former upline (mirrors real direct-selling comp plans per ADR-010's TDSA research).
- Generation: overrides paid by upline **generation** (1st generation of breakaway legs below, 2nd generation, etc.) rather than by flat depth — reuses `users.manager_id`'s existing chain for traversal, adds a `commission_generation_rules` table (company_id, generation_number, rate_type, rate_value) mirroring `commission_override_rules`'s existing shape.

## 4. Affiliate — Full Mode (Trackable Links + Attribution Window)

This is the largest structural addition and the one genuine departure from CLAUDE.md Section 3's "everything behind Sanctum auth" assumption — flagged explicitly, not built silently.

**New schema:**
- `affiliate_links` (id, company_id, agent_id, product_id nullable, token (unique, public-facing), created_at).
- `affiliate_link_clicks` (link_id, clicked_at, ip_hash, user_agent — no raw IP stored at rest, PDPA-consistent with Section 6).
- `affiliate_attribution_settings` (company_id, attribution_window_days, new_vs_returning_rate_differential_enabled) — **BR-7, every value admin-configurable, none decided here.**
- `referrals` gains a nullable `affiliate_link_id` — when a referral originates from a tracked-link lead capture (vs. an agent directly filling the SWS Referral form), this records which link gets attribution credit, feeding the existing `commission_ledger` (BR-4) without changing the ledger's own shape.

**New API surface (genuinely new for this project — public, unauthenticated):**
- `GET /l/{token}` — redirect + click log (public, no Sanctum).
- `POST /api/v1/public/affiliate-leads/{token}` — lead-capture form submission, creates a `Client` + `Referral` (public, no Sanctum, but rate-limited per Section 6 and validated via a Form Request same as any other input — "never trust the client" applies equally to unauthenticated endpoints, arguably more so).

**Architectural flag for the record:** this is the first unauthenticated write endpoint in the entire codebase. It needs the same input-validation, rate-limiting, and abuse-prevention rigor as any auth'd endpoint, and additionally needs bot/spam mitigation (CAPTCHA-equivalent or honeypot field) since it's internet-facing without login — this specific mitigation mechanism is not decided here and should be a line item in TASK-032's acceptance criteria, not assumed.

## Sprint Breakdown

| Sprint | Task | Owner | Depends on |
|---|---|---|---|
| 1 | TASK-027 — plan-type company-default + product-override scoping | ag-dev | none (foundational) |
| 2 | TASK-028 — commission rate scoping by product-category + product | ag-dev | TASK-027 |
| 3 | TASK-029 — Binary matched-volume calculation engine | ag-dev | none (schema already exists) |
| 4 | TASK-030 — Matrix MLM plan type | ag-dev | TASK-027 |
| 5 | TASK-031 — Stairstep/Breakaway + Generation MLM plan types | ag-dev | TASK-027 |
| 6 | TASK-032 — Affiliate trackable links — backend | ag-dev | TASK-027 |
| 7 | TASK-033 — Affiliate trackable links — frontend | ag-ui | TASK-032 |
| 8 | TASK-034 — unified plan-type UI cohesion + full regression QA | ag-ui, ag-qa | TASK-028..033 |
| 9 | TASK-035 — docs finalization + rollout guidance | ag-lead | TASK-034 |

## BR-7 Values Explicitly NOT Decided Here (must ship as admin-editable config/seed)

Attribution-window length; new-vs-returning affiliate rate differential; Binary matching-cycle cadence/cap; Matrix width and depth defaults; Matrix spillover rule; Stairstep/Breakaway rank volume thresholds and count of ranks; rank-recalculation window; Generation override rates per generation number and max generation depth. None of these are recommended or seeded with a specific number by this ADR.

## Out of Scope

- Re-deciding anything ADR-006 already locked (ledger shape, satang storage, renewal, split) — unchanged.
- Choosing the specific bot-mitigation mechanism for the public lead-capture endpoint — deferred to TASK-032's spec.
- Multi-gate tiered feature unlock and agent license/compliance tracking (ADR-010 Options 1 and 3) — not requested in this escalation; remain open, undecided options from ADR-010 if the human wants them later.
