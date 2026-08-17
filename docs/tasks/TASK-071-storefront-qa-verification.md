# TASK-071 — Storefront QA Verification

Owner: **ag-qa**
Related: ADR-020, TASK-068/069/070, DoD §9

## Goal

Gate the storefront redesign PRs before ag-lead merge approval.

## Scope

1. **Tenant isolation** (BR-6, highest priority): for each of the 3 new resources (`storefront_banners`, `product_recommendation_pins`, `product_categories.icon` update) — attempt cross-company access/mutation as both a foreign Company Admin and a foreign Agent → must return 403/404, never leak or mutate another company's row.
2. **`/products/recommended` correctness**: verify pinned-first ordering, correct fallback to ABC-grade auto-fill when pins < slot count, no duplicate products between pinned and auto-filled sets, respects `recommended_slot_count`.
3. **`/products` filter combinations**: category-only, brand-only, price-range-only, and all combined; confirm results stay within the caller's own company.
4. **BR-1 regression check**: confirm the TASK-067 fix (share gate filtered by viewer's own `user_id`) still holds in the redesigned `ProductBrowseView.vue` — test both a Company Admin and a real Agent account, same method as TASK-067's live verification.
5. **Novice-user UAT**: click through all 4 rows on a fresh company with zero banners/pins configured (empty-state check) and again after an admin configures all 3 (banners, category icons, recommendation pins) via `frontend-admin` — confirm changes reflect live on the Agent Portal without a deploy.
6. **Security**: confirm banner `product_id` cannot reference another company's product (both at write-time validation and read-time scoping), confirm the category icon field rejects any value outside the server-side whitelist.
7. **Load/UI**: Desktop/Tablet/Mobile check per DoD §9, loading/empty/error states independent per row (a banner-fetch failure shouldn't block the rest of the page).

## Acceptance Criteria

- All cross-tenant attempts across all 3 new resources return 403/404 — zero exceptions.
- Full click-through report delivered in Thai to the human per this project's existing UAT convention (see UAT-013 for report format precedent).
- No regression in the BR-1 share flow for either Admin or Agent accounts.
