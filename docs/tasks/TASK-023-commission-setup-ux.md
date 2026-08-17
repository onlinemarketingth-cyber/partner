Task: Commission Setup UX — "Apply rate to every product"
Owner: ag-ui + ag-dev
Goal: Let a Company Admin set one commission rate and apply it to every product in one action, instead of re-entering the same rate per product one at a time (ADR-006 decision — Option A: no schema change).
Related: ADR-006 (Commission Configuration Model), BR-2, BR-7, CommissionView/CommissionManagementView (existing commission_rules CRUD)

Input: existing `commission_rules` table/API (no schema change), existing product catalog list (`GET /products`)

Expected output:
- `frontend-admin`: on the commission rules setup screen, an "ใช้อัตรานี้กับทุกสินค้า" (apply this rate to every product) action next to the normal per-product rate form — when used, the frontend loops and calls the existing `POST /commission-rules` (or `PUT` where a rule already exists for that product/cert-tier) once per product, showing a single combined progress/result state rather than one confirmation per product.
- No backend route changes — this is a frontend orchestration convenience over the existing per-rule endpoints.
- A short inline note in the UI that this is a one-time apply, not a standing "default" — a product added later needs the rate (re-)applied (matches ADR-006's documented Option A trade-off; revisit as Option B if this becomes annoying in practice).

Acceptance Criteria:
  - Choosing "apply to every product" for a given cert tier + rate creates/updates a `commission_rules` row for every existing product in that company
  - A single failure (e.g. one product's request 422s) doesn't silently swallow the rest — the UI reports which products succeeded/failed
  - `eslint`/`vue-tsc --build`/`vite build` clean (frontend-admin)

Out of scope (this task):
  - Any schema change (Option B, "default row") — explicitly rejected in ADR-006 Round 1
  - Auto-applying to a newly-created product — not asked for

Depends on: none (existing commission_rules API already supports everything needed)
Blocks: none
