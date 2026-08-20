# ADR-035: Unilevel Commission Drops Cert-Tier Rate Differentiation — Performance-Based Tiering Moves to Stairstep/Breakaway

- **Date:** 2026-08-18
- **Status:** Accepted — scope locked in by the human's explicit answers below. Every rate/threshold value inside this scope remains **undecided (BR-7)** and is flagged, not invented.
- **Author:** ag-lead
- **Depends on:** ADR-006 (Commission Configuration Model), ADR-011 (Unified Multi-Model Agent Architecture — introduced `agent_ranks` for Stairstep/Breakaway), TASK-028 (product/category/company rate scoping)

## Context

The human asked ag-lead to research whether tying commission rate to *certification tier* (Basic/Intermediate/High — earned by passing an Academy exam) is standard industry practice. Findings (see chat, not reproduced here): neither traditional insurance brokerage nor MLM/direct-selling comp plans use training/certification level as the driver of a differentiated commission rate. Insurance commission tiers are production-volume-based (e.g. 60% → 70% → 80% as written premium climbs); MLM rank/override tiers are volume + downline-based. Certification in both industries functions as a **binary access gate** (can you sell at all), never as a **rate multiplier**.

The human confirmed the conclusion applies here: for the "มาตรฐาน" (Unilevel) plan type, a higher commission rate should come from a **performance tier** (sales results), not from **passing more exams**. Cert tier stays exactly as-is for its actual job — BR-1's access gate (`User::hasPassedCertTier('basic')`) is untouched by this ADR.

### Locked-in decisions (human's answers, verbatim intent)

| Question | Decision |
|---|---|
| Should Unilevel drop `cert_tier_id` from rate resolution entirely? | **Yes — cut it.** One rate per product/category/company scope, full stop. |
| Should ag-lead build a new parallel "sales tier" mechanism scoped to Unilevel? | **No.** Reuse the existing Stairstep/Breakaway plan type (`agent_ranks`, built in TASK-031/ADR-011 §3c) for any product/company that wants performance-based tiering. |

## 1. Unilevel: commission_rules Loses the cert_tier_id Dimension

**Current behavior** (`CommissionService::resolveCommissionRule()`): for `plan_type = Unilevel`, a matching row must satisfy `cert_tier_id = agent->highestPassedCertTier()->id` *and* the existing product > category > company scoping from TASK-028.

**New behavior:** drop the `cert_tier_id` filter from the Unilevel resolution path entirely. Resolution becomes purely:
```
product match (exact product_id)
  -> category match (product_category_id, product_id null)
  -> company-wide default (both null)
```
identical to TASK-028's scoping, minus the tier dimension. One rate per product (or category, or company default) — not one row per cert tier.

**Schema:** `commission_rules.cert_tier_id` stays on the table (still used by other plan types' override logic — e.g. `commission_override_rules` still keys manager overrides by the *manager's* cert tier per ADR-011, which is unaffected) but becomes **ignored by Unilevel's own base-rate resolution.** Whether the column becomes nullable-and-unused specifically for Unilevel rows, or is dropped from the Unilevel Form Request's required fields (UI no longer asks for it when creating/editing a Unilevel rule), is an implementation detail for ag-dev/ag-ui to settle together — either way, no new numeric value is invented by this ADR.

**BR-1 is unaffected.** `User::hasPassedCertTier('basic')` continues to gate whether an agent can sell at all — that check has nothing to do with rate resolution and this ADR does not touch it.

## 2. Performance-Based Tiering: Reuse Stairstep/Breakaway, Don't Rebuild It

The system already has exactly the mechanic the human described — `agent_ranks` (company-configurable rank ladder keyed by trailing sales volume, ADR-011 §3c, built in TASK-031) with commission stepping up per rank. Rather than build a second, parallel "sales tier" system bolted onto Unilevel (duplicate schema, duplicate service, duplicate Admin UI, double the surface area to keep in sync), any product or company that wants "sell more, earn a higher rate" should be configured with **`commission_plan_type = StairstepBreakaway`** instead of Unilevel (per-product override already exists — ADR-011 §1, `products.commission_plan_type`).

**Consequence:** "มาตรฐาน" (Unilevel) becomes the deliberately simple, flat-rate plan type — one number per product, no tier of any kind. Stairstep/Breakaway is the answer whenever the human wants commission to scale with results. No new plan type, no new schema, no new Admin screen — `CommissionPlansView.vue`'s existing Agent Ranks tab (TASK-034) already covers configuring rank thresholds and per-rank rates.

## 3. Existing Data — Migration Strategy

Thai Life's currently-configured `commission_rules` rows are set up per cert tier (one row per product × tier). Collapsing to a single per-product rate means an existing conflict has to be resolved for every product that has more than one distinct rate value across its tiers — **which value survives is a real business decision (BR-7), not something ag-lead should pick.**

Rather than silently picking a rule (e.g. "always keep the highest tier's rate") or asking the human to manually recite every product's chosen rate in chat, the migration should ship as an **interactive Artisan command** (same pattern as `admin:create-super` — TASK-205 follow-up, built because `tinker` doesn't work on the Hostinger plan): for each product with more than one distinct rate across its existing per-tier rows, the command prints the tiers and their current rates and prompts the admin to pick which one becomes the new single rate (or type a new one). Products with only one distinct rate across all their tier rows collapse automatically with no prompt needed. This pushes the actual numeric decision to whoever runs the command against production data, at the moment they can see the real numbers — never invented here.

## Sprint Breakdown

| Sprint | Task | Owner | Depends on |
|---|---|---|---|
| 1 | TASK-206 — Backend: drop cert_tier_id from Unilevel's `resolveCommissionRule()`, adjust Form Request | ag-dev | none |
| 2 | TASK-207 — Backend: interactive Artisan migration command for existing per-tier `commission_rules` data | ag-dev | TASK-206 |
| 3 | TASK-208 — Backend feature tests (Unilevel flat-rate resolution, migration command, regression on other 5 plan types + BR-1 gate untouched) | ag-dev | TASK-206, TASK-207 |
| 4 | TASK-209 — frontend-admin: remove cert-tier selector from Unilevel's Commission Rules tab UI (product/category/company scope only); no change needed to the Agent Ranks tab (already exists) | ag-ui | TASK-206 |
| 5 | TASK-210 — ag-qa: verify Unilevel flat-rate math end-to-end, confirm Stairstep/Breakaway unaffected, confirm BR-1 gate unaffected, confirm commission_ledger BR-4 immutability unaffected for historical rows | ag-qa | TASK-206..209 |

## BR-7 Values Explicitly NOT Decided Here

Which per-product rate survives the Thai Life data-collapse (resolved interactively per product, at migration time, by whoever runs TASK-207's command — not invented here). Any Stairstep/Breakaway rank thresholds or per-rank rates for products the human chooses to move onto that plan type instead (unchanged scope — ADR-011 already flagged these as BR-7 and TASK-031 already ships them as admin-editable).

## Out of Scope

- Re-deciding anything about Binary/Matrix/Generation/Affiliate plan types — untouched.
- BR-1's access gate (Basic cert required to sell at all) — untouched, stays exam-based by design.
- Building a second sales-volume tiering mechanism — explicitly rejected by the human in favor of reusing Stairstep/Breakaway (see decision table above).
