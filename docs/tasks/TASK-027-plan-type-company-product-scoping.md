Task: Plan-type company-default + product-override scoping
Owner: ag-dev
Goal: Let a company set a default `commission_plan_type` and let individual products override it, so one company can run Affiliate on one product and MLM on another simultaneously — the foundational schema/service change every other sprint in ADR-011 builds on.
Related: BR-2, BR-7, ADR-006 (existing plan_type column), ADR-011 Section 1, CLAUDE.md Section 5 (TenantScope)
Input:
  - Existing `companies.commission_plan_type` column and `CommissionPlanType` enum (currently `unilevel`, `binary` only).
  - Existing `Product` model (`backend/app/Models/Product.php`), currently no plan-type awareness.
Expected output:
  - Migration: add nullable `commission_plan_type` (string, same values as the enum) to `products`. `NULL` = inherit company default.
  - Extend `App\Enums\CommissionPlanType` with 4 new cases: `Matrix`, `StairstepBreakaway`, `Generation`, `Affiliate` (values: `matrix`, `stairstep_breakaway`, `generation`, `affiliate`). Do not remove or rename the existing 2 cases.
  - `CommissionService` (or wherever plan type is currently read): add a resolution method, e.g. `resolvePlanType(Product $product): CommissionPlanType` returning `$product->commission_plan_type ?? $product->company->commission_plan_type`.
  - `Product` model: add `commission_plan_type` to `$fillable`, cast to the enum.
  - Admin API: allow setting `commission_plan_type` on a product (nullable — explicit "inherit" option in the UI is ag-ui's job in TASK-034, not this task).
  - API Resource: expose both the product's own value (nullable) and the resolved effective value, so ag-ui doesn't have to duplicate the resolution logic client-side.
Acceptance Criteria:
  - A product with `commission_plan_type = NULL` resolves to its company's plan type.
  - A product with a non-null `commission_plan_type` resolves to its own value regardless of company default.
  - Existing companies/products (all currently NULL on the new column) behave identically to today — zero behavior change until a product explicitly overrides.
  - Tenant isolation must pass (cross-tenant access → 403/404).
  - Form Request validates `commission_plan_type` is one of the enum's valid values or null.
  - Tests cover: inherit case, override case, and that setting a foreign company's product 404s/403s.
Out of scope: Actually building Matrix/Stairstep/Generation/Affiliate calculation logic (TASK-029..032) — this task only wires the plan-type *selection*, not any new plan's math.
Depends on: none
Blocks: TASK-028, TASK-030, TASK-031, TASK-032
