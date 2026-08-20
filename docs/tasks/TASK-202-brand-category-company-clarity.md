# TASK-202 — make the "จัดการแบรนด์ / หมวดหมู่" dialog say which company each row belongs to

- **Owner:** ag-lead (spec) → ag-dev (backend) → ag-ui (frontend-admin) → ag-qa
- **Date:** 2026-08-19
- **Status:** implemented in the same session (backend + frontend), pending ag-qa
- **Human:** "เข้าใจยากมากเลย UI เสนอปรับ UI ให้สามารถเลือกบริษัทได้สะดวกขึ้นและต้องแสดงชัดเจนว่า
  แบรนด์ไหน อยู่บริษัทอะไร" — after picking "GENESENN Health" in the dialog's company picker and
  still seeing another company's brand in the list below it. Design was reviewed as a
  before/after mockup and approved with "ทำเลย" (Option C — scoped by default, "ทุกบริษัท"
  available).
- **Related:** BR-6 / Section 5 (multi-tenancy — this task changes *presentation only*, no
  scope/permission rule moves), TASK-069 (`Product.company_id` surfaced client-side for the same
  reason: TenantScope does not narrow a Super Admin, so a flat cross-company list needs a label),
  TASK-091 (`DeletionGuard` — the products_count column exists to predict its refusal), TASK-102
  (the manage drawer this dialog grew out of).

---

## 1. Root cause (not a styling problem)

The dialog's company `<select>` fed **only the create payload**. The list underneath was
`GET /brands` verbatim — every company's rows, because `TenantScope` deliberately does not narrow
a Super Admin — with **no company shown on any row**. Two halves that each worked as written
combined into a screen that reads as broken.

Compounding it: `BrandResource` has always returned `company_id`; the frontend's
`interface Brand` simply omitted the field. The data needed to fix this was already on the wire.

## 2. What changed

**Backend (ag-dev)**

| File | Change |
|---|---|
| `BrandController::index` | `paginate()` → `get()`, plus `withCount('products')` |
| `ProductCategoryController::index` | `withCount('products')` (already used `get()`) |
| `BrandResource` / `ProductCategoryResource` | `products_count` via `whenCounted('products')` — absent from show/store/update payloads, so no other consumer's shape changes |

The pagination change is a **bug fix, not a preference**: every consumer renders `data` and none
renders a pager, so with the default 15-per-page, brand #16 onward silently did not exist in the
UI. For a Super Admin whose list spans all companies that ceiling is reached with a handful of
companies. `ProductCategoryController` already used `get()` — the two were inconsistent.

**Frontend (ag-ui) — `frontend-admin/src/views/ProductCatalogView.vue`**

1. `interface Brand` / `interface ProductCategory` gain `company_id` and optional `products_count`.
2. Company picker **moved above the tabs** into a tinted bar — it scopes both tabs, and sitting
   inside one tab's panel made it look like it only applied to that tab.
3. The picker now **scopes the list**, not just the create payload:
   - company selected → that company's rows are the editable list; every other company's rows
     follow in dimmed, action-less groups (kept visible on purpose — "where did my brand go" is
     the confusion being fixed, so nothing silently disappears);
   - `null` → labelled **"ทุกบริษัท (จัดกลุ่มให้)"**, all rows, grouped per company, all editable.
4. Every group carries a **company chip**; the header also shows the row count for that company.
5. Create button names its target (`+ เพิ่มแบรนด์ ใน GENESENN Health`) and is **disabled** until a
   company is picked, instead of letting the admin fill the form and fail at save.
6. Edit form gains a **"กำลังแก้ไข · <name> · <company>"** caption + highlighted border — before,
   the row was replaced by an unlabelled input and appeared to vanish from the list.
7. **Search box** per tab, and a **"ใช้กับสินค้า N"** column per row.

## 3. Explicitly NOT changed

- No permission, policy, scope or tenancy rule. A Company Admin still sees only their own company
  (TenantScope), so for them the grouping collapses to one unlabelled group — the chips and the
  picker are Super-Admin-only UI.
- No change to how brands/categories are created or validated server-side.
- `CatalogManagementView.vue` (ADR-036 global catalog) is a different screen with genuinely global
  rows and is untouched here.

## 4. Acceptance criteria

- [x] Brand/category rows display their owning company (Super Admin) — `company_id`, no new endpoint
- [x] Picking a company filters the editable list; "ทุกบริษัท" groups everything
- [x] Create is impossible (button disabled) until a company is chosen — no save-time surprise
- [x] `GET /brands` returns every brand, not the first 15 — feature test asserts 20/20
- [x] `products_count` present on index for both resources — feature test asserts 2 and 0
- [x] Pint + full backend suite green (1591 passed)
- [ ] tenant isolation re-confirmed by ag-qa: Company Admin still sees only their own rows and
      cannot reach another company's brand/category by id (403/404) — unchanged by this task, but
      it touched the list endpoint, so it must be re-verified
- [ ] UAT on MySQL 8 (the suite runs on SQLite — see the open ADR-037 discussion)

## 5. Follow-ups (not in this task)

- `GET /products` in the same screen is still `paginate()`-shaped — check whether it has the same
  silent-truncation problem before the product list grows.
- `CatalogManagementView.vue`'s create handlers still lack try/catch (same defect class fixed here
  in `submitBrand`/`submitCategory`).
