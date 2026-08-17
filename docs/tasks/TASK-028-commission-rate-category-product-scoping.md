Task: Commission rate scoping by product-category and product
Owner: ag-dev
Goal: Let `commission_rules` define a rate at the product-category level and/or the company-wide default level, not only per-individual-product as today — closing the gap ADR-006 flagged and deferred ("Option B: nullable product_id as company-default row").
Related: BR-2, BR-7, ADR-006, ADR-011 Section 2, CLAUDE.md Section 5 (TenantScope)
Input:
  - Existing `commission_rules` table (`company_id`, `cert_tier_id` NOT NULL, `product_id` NOT NULL, `rate_type`, `rate_value`, renewal fields from TASK-024).
  - Existing `product_categories` table and `Product::category()` (`products.category_id` FK).
Expected output:
  - Migration: make `commission_rules.product_id` nullable; add nullable `commission_rules.product_category_id` (FK to `product_categories`, restrictOnDelete, mirrors the naming/relationship pattern of `products.category_id`).
  - DB/app-level constraint enforced in the Form Request (not just documented as a convention): a row may have `product_id` set, OR `product_category_id` set, OR neither (company-wide default) — never both `product_id` AND `product_category_id` set on the same row.
  - `CommissionService`: implement the 4-step resolution order from ADR-011 Section 2 — (1) exact `product_id` match, (2) `product_category_id` match via the product's `category_id`, (3) company-wide default row (`product_id` AND `product_category_id` both NULL) for that cert tier, (4) no match → existing error path unchanged.
  - Update `commission_rules_lookup_idx` index to remain useful under the new nullable columns (consider a composite index covering `company_id, cert_tier_id, product_category_id` in addition to the existing one).
  - Admin UI API: endpoint to create a category-level or company-default rule (ag-ui builds the screen in TASK-034; this task only needs the API to accept it).
Acceptance Criteria:
  - Every existing `commission_rules` row (all currently `product_id` NOT NULL) continues to resolve at step 1 exactly as before — zero behavior change for existing data.
  - A new category-level row is picked up for any product in that category lacking its own product-specific row.
  - A new company-wide default row is picked up only when neither a product-specific nor category-specific row exists.
  - Product-specific row always wins over category-specific; category-specific always wins over company-default.
  - Attempting to set both `product_id` and `product_category_id` on one row is rejected by the Form Request (422).
  - Tenant isolation must pass (cross-tenant access → 403/404).
  - Tests cover all 4 resolution steps plus the both-set rejection case.
Out of scope: Changing how the commission ledger (BR-4) records which rule fired — it already stores the resolved rate at time of trigger; no change to `commission_ledger`'s shape.
Depends on: TASK-027
Blocks: TASK-034
