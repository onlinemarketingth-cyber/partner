# ADR-020: Storefront Redesign — Configurable 4-Row Product Page (Agent Portal)

- **Date:** 2026-07-31
- **Status:** Accepted — planning complete, not yet built. 4 decisions human-confirmed 2026-07-31.
- **Author:** ag-lead
- **Related:** CLAUDE.md §5/BR-6 (multi-tenant), BR-7 (admin config, never hardcoded), §7 (design-system duplication across `frontend`/`frontend-admin`). ADR-018 (theming — `nav_icon_overrides` icon-picker pattern reused here). TASK-040 (`ProductGradingService` ABC/Pareto — reused for auto-fill). TASK-056 P3 (original `ProductBrowseView.vue`).

## Context

Human request: redesign the Agent Portal's product/catalog page (`frontend/src/views/ProductBrowseView.vue`) from its current single-section "search bar + product grid" layout into a 4-row consumer-app-style storefront (reference: a home-services app mockup), and make every row's content admin-configurable from `frontend-admin`:

1. Search + filter bar
2. Clickable banner (carousel)
3. Product category row, rendered as icons
4. "Recommended for you" product row

This is the first Agent-Portal page redesigned around **admin-curated merchandising content** rather than pure live data — it introduces two new content types (banners, recommended-product pins) that previously had no precedent in this codebase, so an ADR is warranted before task specs are cut.

## Decisions (human-confirmed 2026-07-31)

1. **Recommended for you = hybrid.** Admin manually pins products (ordered) first; if pinned count is below the row's slot count, remaining slots auto-fill from the existing `ProductGradingService` (TASK-040 ABC/Pareto grading, company-wide, already computed live from real sales — not personalized per agent). No new algorithm invented; reuses what's already been reviewed and shipped.
2. **Banner click-target = internal product pages only.** A banner slide links to exactly one `Product` in the same company (dropdown picker in admin, not a free-text URL). External URLs and non-product internal routes are explicitly out of scope this round — keeps the feature surface small and avoids an open redirect / arbitrary-link review burden.
3. **Category icons reuse the existing curated icon set**, not per-category image uploads. Same pattern as `company_theme_settings.nav_icon_overrides` (ADR-018): admin picks a name from `Icon.vue`'s existing ~130-icon whitelist via a picker grid. No new upload/storage infrastructure.
4. **Filter fields = category + brand + price range.** All three already exist as real columns (`products.category_id`, `products.brand_id`, `products.price_satang`) — no schema invented to support the filter.

## Data model (new)

All new tables are `company_id` + `TenantScope`'d per BR-6, same as every existing table in this domain.

**`storefront_banners`** (row 2 — admin CRUD, replaces nothing, net-new):
- `company_id`, `product_id` (FK, required — decision #2), `image_path` (public disk, same upload/validation convention as `announcements.image_path`), `title` (nullable, optional overlay caption), `sort_order`, `is_active`, timestamps.

**`product_categories.icon`** (row 3 — new nullable column on existing table):
- `icon` (string, nullable) — stores an `Icon.vue` name, exactly like `nav_icon_overrides`' value shape. No new table needed; `ProductCategory` already exists and is already tenant-scoped.

**`product_recommendation_pins`** (row 4, manual half of the hybrid — admin CRUD, net-new):
- `company_id`, `product_id` (FK), `sort_order`, `is_active`, timestamps. Unique on `(company_id, product_id)`.
- Auto-fill half needs **no new table** — `ProductGradingService`'s existing ABC output is queried live at request time, same as `ProductPerformanceView.vue` already does.

## API changes (new)

- `GET /storefront-banners` (public/agent-read, tenant-scoped) + Admin CRUD (`POST`/`PUT`/`DELETE /storefront-banners`) — same shape as `AnnouncementController`.
- `ProductCategoryController` — `icon` added to `$fillable`, validated against the same curated whitelist used by the icon picker (reject unknown names server-side too, not just client-side).
- `GET /products/recommended` — new endpoint: returns pinned products (by `sort_order`) up to a configurable slot count, then fills remaining slots with top ABC-grade products (excluding already-pinned `product_id`s) by sales volume desc. Slot count itself is **BR-7: not a business rule invented by ag-lead** — ships with a sensible default (8) but must be admin-editable (see Config Health flag below), not hardcoded in the Service.
- `GET /products` — extend with optional `category_id`, `brand_id`, `price_min_satang`, `price_max_satang` query filters (mirrors the existing inline-`validate()` filter pattern already used by `AgentCommissionSummaryController`/`CommissionLedgerController`).
- Admin CRUD for `product_recommendation_pins` (list all products with pin toggle + drag/number `sort_order`, most naturally added to the existing Admin product list/`ProductCatalogView.vue` rather than a new screen).

## Frontend changes (both apps)

- **`frontend-admin`**: new admin screens/sections — Banner CRUD (image upload + product picker + sort order + active toggle), category icon picker (reuses/extracts the `ICON_CHOICES` picker pattern from `ThemeSettingsView.vue` into a shared component so it isn't duplicated a third time), recommendation pin toggle on the product list, and a "จำนวนสินค้าแนะนำ" (recommended row slot count) config field — this last one is the BR-7 admin-editable value that prevents the slot count from being a hardcoded magic number.
- **`frontend`**: `ProductBrowseView.vue` restructured into 4 rows as described. Existing BR-1 share-gate logic (per TASK-067 fix) and the "แชร์" action are preserved unchanged inside the product grid/rows — this ADR only changes layout and data sourcing, not the sharing feature itself.

## Consequences

- New tables follow the exact same tenant-isolation, Policy, and Form-Request conventions as every other table in this codebase — ag-qa must add the standard cross-company/cross-agent 403/404 test suite for all 3 new endpoints (banners, recommendation pins, category icon update).
- The recommended-row slot count is the one BR-7 flag from this ADR that needs a company-level config field (not just a code constant) — flagged explicitly so it isn't missed at review.
- Banner `product_id` being required (not nullable) means a banner cannot be created before at least one product exists — acceptable, matches real usage order (products always precede merchandising).
- Icon picker component gets **extracted into a shared component** during this work since it will now be used in two places (`nav_icon_overrides` + category icons) — small refactor, prevents a third copy-paste divergence later.

## Out of scope this round (explicitly deferred)

- External-URL / non-product banner targets.
- Per-category custom icon image upload.
- Personalized (per-agent) recommendation ranking.
- Banner scheduling (start/end dates) — `is_active` toggle only, no time-window like `Announcement` has.
