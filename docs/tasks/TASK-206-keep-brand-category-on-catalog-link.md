# TASK-206 — a catalog-linked product must keep its company's brand/category

- **Owner:** ag-lead (audit + spec) → ag-dev (implemented in session) → ag-qa
- **Date:** 2026-08-19
- **Status:** implemented, pending ag-qa
- **Human:** asked "หากสินค้าในหมวด หรือแบรนด์ มีการขายต่างบริษัทระบบจัดการอย่างไร"; the audit that
  question triggered found the defect below. Human chose **Option A — ผูกกลับเข้าแบรนด์ของบริษัท**
  out of three presented, and asked for the full impact audit first.
- **Related:** ADR-036 §3 (amended by this task), TASK-028 (category-scoped commission rules),
  ADR-026 (pipeline template chain), BR-2 / BR-4 / BR-6.

---

## 1. Audit — everything that reads `products.brand_id` / `products.category_id`

`ProductCatalogLinkService::link()` used to set both to `NULL`. Full sweep of backend + both
frontends for readers of those columns:

| Reader | What breaks when the column is null | Severity |
|---|---|---|
| `CommissionService::resolveCommissionRule()` (line ~509) | The category-scoped rung (`product_id IS NULL AND product_category_id = X`) cannot match → falls through to the company-default rate. **Wrong payout**, recorded in an immutable ledger row. | **BR-2 / BR-4 — money** |
| `PipelineTemplateResolver::categoryTemplateId()` (line ~283) | ADR-026's `product ?? category ?? company` chain loses its middle rung → referral runs the company-default journey | High |
| `ProductController::index` `brand_id` / `category_id` filters | Linked product is unfindable by brand/category in the Admin list | Medium |
| `frontend/src/views/ProductBrowseView.vue` | Same, on the Agent Portal storefront facets | Medium |
| `frontend-admin/ProductEditView.vue`, `CommissionPlansView.vue` | Category pickers for commission-rule scoping | Medium |
| `CommissionRuleService` (`product_category_id` scope + overlap check) | Rules can be created against a category no linked product resolves to | Medium |
| `DeletionGuard` / `BrandController::destroy` | A brand/category with only linked products would look unused and be deletable | Low |

Not affected: anything reading `catalog_brand_id` / `catalog_category_id` (display path), price,
commission config, vouchers, referrals, orders, ledger.

## 2. Fix

`link()` now keeps the product pointing at **its own company's** brand/category row, mirrored by
name from the catalog item's global brand/category, created in that company if absent
(`firstOrCreate` on `(company_id, name)`, `TenantScope` bypassed so the lookup happens in the
PRODUCT's company, never the acting Super Admin's).

`name` / `description` / `spec_description` are still nulled — those are display-only and every
reader goes through `ProductResource`'s catalog resolution.

Display behaviour is unchanged: a linked product still shows the catalog's name/brand/category.

## 3. Tests added (`ProductCatalogTest`, all green — suite 1598 passed)

- linking mirrors the catalog brand+category into the product's own company, with the product's
  `company_id` on both mirrored rows (BR-6)
- linking a second product reuses the existing same-named brand instead of duplicating it
- **a catalog-linked product still matches its category-scoped commission rule** — the regression
  guard for the money bug
- the pre-existing link test updated: `brand_id`/`category_id` asserted **not null** now

## 4. Accepted consequence

Renaming a global catalog brand does **not** rename the mirrored per-company rows. They are a join
target, not a display source, and cascading would fight a Company Admin who renamed their own copy.
Only the catalog name is ever displayed for a linked product. If drift becomes a real problem the
answer is a re-sync artisan command, not a live cascade — that needs its own ADR.

## 5. Still open

- [ ] ag-qa: verify on MySQL that linking an existing product does not disturb its existing
      commission rules, and that a Company Admin cannot cause a mirrored row to be created in
      another company
- [x] Data repair for products **already linked before this fix** — shipped as
      `php artisan catalog:backfill-linked-taxonomy` (see §6)


## 6. Backfill command (added same session, human: "แก้ไขเลย")

```bash
php artisan catalog:backfill-linked-taxonomy --dry-run   # list what would change
php artisan catalog:backfill-linked-taxonomy             # write
```

Sweeps every company (`withoutGlobalScope(TenantScope)` — a console maintenance job with no
authenticated actor, same rationale as `DispatchDueRenewalCommissions`) for products with
`catalog_item_id IS NOT NULL AND (brand_id IS NULL OR category_id IS NULL)` and mirrors the
catalog item's brand/category into the product's own company through the exact same
`ProductCatalogLinkService` code path as `link()` — no duplicated logic.

- **Idempotent**: `backfillLocalTaxonomy()` only ever fills a `null`, never overwrites a value an
  admin has since set, so re-running is a no-op.
- **`--dry-run`** prints the plan and writes nothing.
- A product whose catalog item no longer exists is reported as `SKIPPED` with a non-zero exit
  code rather than silently passed over — it needs a manual unlink.
- Covered by `test_backfill_command_repairs_products_linked_before_the_fix` (dry run writes
  nothing → real run repairs → standalone products untouched → second run is a no-op).
