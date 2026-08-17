# TASK-068 — Storefront Backend: Schema + APIs (Banners, Category Icons, Recommended Products, Product Filters)

Owner: **ag-dev**
Related: ADR-020, BR-6 (multi-tenant), BR-7 (admin config), TASK-040 (`ProductGradingService`)

## Goal

Build the backend foundation for ADR-020's 4-row storefront: banner CRUD, category icon field, hybrid recommended-products endpoint, and extended product filters.

## Input

- Existing `Product`, `ProductCategory`, `Brand` models (all `company_id` + `TenantScope`).
- Existing `ProductGradingService` (TASK-040) — do not duplicate its ABC logic, call into it.
- Existing `Announcement` upload/validation pattern for `image_path` as the reference for banner image handling.

## Expected output

1. **Migration + model**: `storefront_banners` (`company_id`, `product_id` FK `restrictOnDelete`, `image_path`, `title` nullable, `sort_order`, `is_active`, timestamps) + `StorefrontBanner` model with `TenantScope`.
2. **Migration**: add nullable `icon` (string) column to `product_categories`.
3. **Migration + model**: `product_recommendation_pins` (`company_id`, `product_id` FK, `sort_order`, `is_active`, timestamps, unique `[company_id, product_id]`) + model with `TenantScope`.
4. **Migration**: add a `recommended_slot_count` (integer, default 8) config field — place on `company_theme_settings` if a "storefront config" concept doesn't already exist, or a new minimal `storefront_settings` row-per-company table if that's cleaner; ag-dev's call, note the choice in the PR description.
5. **Policies**: `StorefrontBannerPolicy`, `ProductRecommendationPinPolicy` — Company Admin/Super Admin only for write; any authenticated company member can read (same visibility rule as `ProductCategoryPolicy`).
6. **Form Requests**: `StoreStorefrontBannerRequest`/`UpdateStorefrontBannerRequest` (image required on create, `product_id` must belong to caller's company), `UpdateProductCategoryRequest` extended with `icon` validated against a server-side whitelist constant (same list the frontend icon picker uses — keep in sync, see TASK-069), `StoreProductRecommendationPinRequest` (`product_id` must belong to caller's company, `sort_order` integer).
7. **Controllers + routes**:
   - `StorefrontBannerController` — full CRUD, `GET /storefront-banners` readable by any authenticated company user (Agent Portal needs it), write restricted by Policy.
   - `ProductCategoryController::update()` — accept `icon`.
   - `ProductRecommendationPinController` — CRUD for pins (Admin only).
   - `ProductController::recommended()` → `GET /products/recommended`: fetch active pins ordered by `sort_order` up to `recommended_slot_count`; if fewer than slot count, fill remainder via `ProductGradingService`'s "A" grade products (excluding already-pinned `product_id`s) ordered by sales volume desc, until slot count reached or products exhausted.
   - `ProductController::index()` — add optional `category_id`, `brand_id`, `price_min_satang`, `price_max_satang` query filters via inline `validate()`, same pattern as `AgentCommissionSummaryController`.
8. **API Resources**: `StorefrontBannerResource` (include nested minimal product info: id, name, thumbnail — enough for the frontend to render+link without a second request), `ProductCategoryResource` add `icon`.
9. **Feature tests**: tenant isolation (cross-company banner/pin access → 403/404) for all 3 new resources, `icon` rejects a value outside the whitelist, `/products/recommended` returns pinned-then-auto-filled in correct order and respects `recommended_slot_count`, `/products` filter combinations (category+brand+price range) return correct scoped results.

## Acceptance Criteria

- All new tables have `company_id` + `TenantScope`; ag-qa's standard cross-tenant test suite passes.
- No business value (slot count, icon whitelist) hardcoded inside a Service — slot count lives in a DB config field; icon whitelist is a named constant referenced by both validation and (later) the frontend picker, not duplicated ad hoc.
- `/products/recommended` never returns a product from a different company, even if a pin/grading calculation is manipulated.
- Money fields (`price_min_satang`/`price_max_satang`) follow BR-3 (integer satang, no float).
- Existing `/products` behavior (search `q`, `is_active`, pagination) unchanged for callers not using the new filter params.

## Out of scope

External-URL banners, per-category icon image upload, personalized recommendation ranking — see ADR-020 "Out of scope this round".
