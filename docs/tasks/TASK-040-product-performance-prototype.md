# TASK-040 — Product-view IA items 2.2/2.3 (prototype)

Owner: ag-dev (backend hardening) / ag-ui (UI polish) / ag-qa (tests)
Status: prototype built by ag-lead 2026-07-27, verified end-to-end via live browser test against real data 2026-07-27. Pending human sign-off on the BR-7 items below before production-ready.

## Goal

Ship มุมที่ 2 (Product view) sub-features: product ABC grading by real sales (2.2) and product price promotions (2.3a agent bonus reuse + 2.3b customer-facing price discount).

## What was built (this pass)

- Backend: `ProductGradingService` (Pareto ABC grading, computed live on every request — no persisted `grade` column) + `GET /api/v1/products-abc-grades?window_days=30|90|365`.
- Backend: `product_price_promotions` migration/model/policy/Service/Requests/Resource/Controller + full CRUD routes, tenant-scoped (BR-6), mirrors `agent_promotions` pattern.
- Frontend (Admin): `ProductPerformanceView.vue` — Section A (ABC grade table + period selector), Section B (price promotion CRUD), reached via a link button on `ProductCatalogView.vue`'s HeroHeader actions (no top-nav change).
- Not built this pass: any customer/checkout-facing surface for price promotions; any Service that pays a `agent_promotions`-style bonus tied to product performance grade.

## Verification performed

- Migration run by user, confirmed.
- `npx vue-tsc --build` + `npx eslint` clean (frontend-admin).
- Live browser test against real Thai Life data: ABC grade table renders correct Pareto math (Premium Package…QA Stairstep Package = A at ≤80% cumulative, QA Unilevel Package = B at 87.84%, Standard Package = C at 100%, stray "ddd" test product = D, 0 sales).
- Period-selector confirmed to actually re-fetch with the correct param: clicking "365 วัน" fired `GET /products-abc-grades?window_days=365` → 200 (network-inspected). The identical numbers seen between "30 วัน" and "ทั้งหมด" during testing are explained by all QA seed sales being recent — not a bug.
- Both mandatory disclosure lines (estimate-not-historical for grading; display-only-not-commission for price promotions) confirmed rendered in the live UI.

## Related

BR-2 (tiered commission — price promotions currently do NOT feed into it, see open question 1), BR-3 (money as integer satang), BR-6 (tenant isolation), BR-7 (unfinalized business values), Section 5 (multi-tenancy).

## Open BR-7 questions — human must confirm before real rollout

1. **Price promotion → commission interaction**: `product_price_promotions` is currently DISPLAY-ONLY. `CommissionService` still computes against `Product.price_satang` regardless of an active discount. Needs a decision: should commission be computed on the discounted price or the list price once this is wired to real checkout? (Touches BR-2/BR-4 — larger follow-up task.)
2. **ABC grade revenue figure is an estimate, not historical fact**: `estimated_revenue_satang = sold_count × product's CURRENT price_satang`. No historical sale-price snapshot exists anywhere in the schema (`CommissionLedger.amount_satang` is commission paid, not sale price; `Referral` has no price field). If a product's price ever changes, past grades computed today will not match grades computed after the price change, even with identical sales. Acceptable for a prototype; flag if the human wants a real point-in-time price snapshot captured at `Complete Payment` going forward.
3. **Stray "ddd" test product**: still present in the DB, surfaces as grade D (0 sales) in the live grade table. Pre-existing data-cleanup item, not new to this feature — flagging again since it's now visibly exposed in a new screen.
4. **No badge/bonus tied to grade**: unlike Agent-view gamification (XP/Badge, BR-5), a product's ABC grade currently has no downstream effect (no auto-promotion suggestion, no featured placement). Out of scope unless the human wants one.

## Acceptance criteria (for the eventual "done" state, not yet all met)

- [ ] Human has answered the 4 open questions above
- [ ] Price promotion wired into real commission calculation (if confirmed desired)
- [ ] Feature tests cover tenant isolation (cross-company 403/404) for `product_price_promotions` and the ABC grading endpoint
- [ ] ag-qa has run a full novice-user UAT pass
- [ ] Reviewed and approved by ag-lead against Section 9 Definition of Done

## Out of scope (this pass)

Customer/checkout-facing display of price promotions, commission calculation changes, historical price-snapshot capture, product-grade-triggered notifications or badges.
