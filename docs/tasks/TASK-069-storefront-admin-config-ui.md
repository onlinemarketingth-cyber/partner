# TASK-069 — Storefront Admin Config UI (frontend-admin)

Owner: **ag-ui**
Related: ADR-020, TASK-068 (backend, must land first or be developed in parallel against the same contract)

## Goal

Give Company Admin/Super Admin full control over the 3 configurable storefront rows from `frontend-admin`, with no code deploy needed to change merchandising content.

## Input

- New endpoints from TASK-068: `storefront-banners` CRUD, `product-categories` (extended with `icon`), `product-recommendation-pins` CRUD, `recommended_slot_count` config field.
- Existing precedent: `ThemeSettingsView.vue`'s `ICON_CHOICES` picker grid (`nav_icon_overrides`) — **extract this into a shared `IconPicker.vue` design-system component** (per ADR-020 consequence) instead of writing a third copy.
- Existing precedent: `AnnouncementsView.vue`'s image upload flow (size guard, preview) for the banner image field.
- Existing precedent: `ProductCatalogView.vue` for where category management currently lives — icon picker should be added there, not a new screen.

## Expected output

1. **`IconPicker.vue`** extracted to `frontend-admin/src/design-system/components/` (and duplicated into `frontend/src/design-system/components/` only if the Agent Portal ever needs it — not expected this round, so admin-only is fine per CLAUDE.md §7's duplication convention, only copy when actually needed on both sides).
2. **Category icon picker** wired into `ProductCatalogView.vue`'s category edit form using `IconPicker.vue`; `icon` persisted via the extended `UpdateProductCategoryRequest`.
3. **New "แบนเนอร์" (Banners) management section** — CRUD list + create/edit drawer: image upload (reuse `AnnouncementsView.vue`'s compress/size-guard helper), product picker (searchable dropdown over the company's own products), `title` optional text field, `sort_order` (drag or numeric), `is_active` toggle. Use `ConfirmDialog.vue` (per TASK-066 convention — **no native `window.confirm()`**) for delete actions.
4. **Recommendation pin controls** — added to the existing product list/`ProductEditView.vue`: a "ปักหมุดแนะนำ" toggle + `sort_order` field, visible only to Company Admin/Super Admin.
5. **"จำนวนสินค้าแนะนำ" (recommended slot count)** numeric input somewhere sensible in existing settings (co-locate with the Banners section or Theme Settings — ag-ui's call on exact placement, just don't hardcode it in a Vue file as a client-side constant).

## Acceptance Criteria

- All 3 new admin surfaces (banners, category icon, recommendation pins) are reachable without editing code — matches BR-7 in practice, not just schema.
- Banner delete and any other destructive action uses `ConfirmDialog.vue`, not `window.confirm()` (TASK-066 convention).
- Icon picker shows only the server-side-validated whitelist (TASK-068) so an admin can never pick a name the backend will reject.
- Tenant isolation: Company Admin can only manage/see their own company's banners/pins/categories (relies on backend TenantScope + Policy from TASK-068, but UI must not leak cross-company data via any dropdown, e.g. the banner's product picker must only list the caller's own company's products).
- `vue-tsc --build` + `eslint` clean.

## Out of scope

Same exclusions as ADR-020 (external-URL banners, per-category image upload, banner scheduling).
