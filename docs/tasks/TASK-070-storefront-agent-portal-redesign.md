# TASK-070 — Agent Portal Product Page Redesign (4-Row Storefront)

Owner: **ag-ui**
Related: ADR-020, TASK-068 (backend), TASK-067 (BR-1 share-gate fix — must remain intact), TASK-056 P3 (original view)

## Goal

Restructure `frontend/src/views/ProductBrowseView.vue` into the 4-row consumer-app-style layout, sourced entirely from the new admin-configurable data, per the human-provided reference mockup (search+filter row, clickable banner, category icon row, recommended-for-you row).

## Input

- New/extended endpoints from TASK-068: `GET /storefront-banners`, `GET /product-categories` (with `icon`), `GET /products/recommended`, `GET /products` with `category_id`/`brand_id`/`price_min_satang`/`price_max_satang`.
- Existing `hasPassedBasic` BR-1 gate logic in `ProductBrowseView.vue` (already fixed per TASK-067 — filter by `authStore.user?.id`, do not regress this).
- Existing `Icon.vue` for rendering category icons (same component already used everywhere).
- Existing `ShareLinkModal.vue` / `shareProduct()` flow — unchanged.

## Expected output

**Row 1 — Search + Filter:**
- Keep the existing debounced text search (`q`).
- Add a filter control (icon button opening a panel/sheet) for category, brand, price range — maps to the new `/products` query params.

**Row 2 — Banner:**
- Horizontally scrollable/swipeable carousel of `storefront-banners` (active, ordered by `sort_order`).
- Tapping a banner navigates to that banner's linked product (open the same share/detail flow a product card would, or navigate to a product detail view if one exists — reuse existing navigation, do not invent a new product detail route unless none exists).
- Empty state: row 2 simply doesn't render if there are zero active banners (no placeholder needed — admin-optional content).

**Row 3 — Category icons:**
- Horizontal row of category icons + labels from `GET /product-categories` (`icon` + `name`), tenant-scoped.
- Tapping a category applies it as the `category_id` filter for row-driven product results (i.e., re-uses the row-1 filter state — clicking a category icon is a shortcut into the same filter, not a separate mechanism).

**Row 4 — Recommended for you:**
- Product row/grid sourced from `GET /products/recommended`.
- Cards reuse the existing product card component/markup (thumbnail, name, price, "แชร์" button with the existing BR-1 gate) so there is exactly one product-card implementation, not two.

**General grid below (existing behavior, retained):**
- The full searchable/filterable product grid stays, now respecting whatever filter state rows 1/3 have set. This is functionally the pre-existing view, just re-scoped to sit under the new rows.

## Acceptance Criteria

- BR-1 share gate (post-TASK-067 fix) is unchanged and still filters certifications by the viewer's own `user_id` — this task must not reintroduce that bug.
- All 4 rows source data from the new endpoints; nothing is hardcoded (no client-side banner list, no hardcoded category icon map).
- Works correctly across Desktop/Tablet/Mobile with loading/empty/error states for each of the 4 rows independently (a banner-fetch failure must not block the product grid from rendering, etc. — per DoD §9).
- Core "search → find product → share" workflow remains completable in ≤3 clicks (DoD §9), despite the added rows.
- `vue-tsc --build` + `eslint` clean.

## Out of scope

Product detail page redesign (unless one doesn't exist yet, in which case flag back to ag-lead — do not silently invent a new route/page shape without confirming).
