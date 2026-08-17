# UAT-001: Product Catalog

Manual test script — run this after `php artisan migrate:fresh --seed`
and starting both the backend (`php artisan serve --port=8010`) and the
Admin app (`cd frontend-admin && npm run dev`, http://localhost:5273).

This exists because the automated tests in `tests/Feature/Catalog/` are
written but have never actually been executed (no PHP runtime in the
dev sandbox that built them) — treat this checklist as the real
first verification, not a formality on top of already-passing tests.

Seeded accounts (Section 1 of SETUP.md), all password `password`:
| Role | Email |
|---|---|
| Super Admin | superadmin@example.test |
| Company Admin (Thai Life) | admin@thailife.test |
| Agent (Thai Life) | agent@thailife.test |

---

## 1. Automated tests (run these first — fastest signal)

- [ ] `php artisan test --filter=Catalog` — all of BrandTest, ProductTest, CommissionRuleTest pass
- [ ] `./vendor/bin/pint --test` — no formatting violations in the new files
- [ ] If either fails, stop and fix before continuing to manual UAT below — the manual steps re-check the same rules by hand and will waste time re-discovering the same bug.

## 2. Admin app — Company Admin (admin@thailife.test)

- [ ] Log in, land on Admin home. A **Product catalog** card is visible and clickable (not grayed out / "เร็วๆ นี้").
- [ ] Click it — lands on the catalog screen with 4 tabs: แบรนด์ / หมวดหมู่ / แพ็กเกจ / อัตราคอมมิชชั่น.
- [ ] **แบรนด์ tab**: "Thai Life Wellness" (seeded) is listed. Click "+ เพิ่มแบรนด์", enter a name, save — new brand appears without a page reload.
- [ ] **หมวดหมู่ tab**: "Annual Health Package" (seeded) is listed. Add a new category the same way.
- [ ] **แพ็กเกจ tab**: "Standard Package" (890000 satang) displays as **8,900 บาท** — not 890000, not 8900.00, not with decimals. Same for "Premium Package" → **9,900 บาท**.
- [ ] Add a new product, price in THB (e.g. `1500`) — after save, confirm it shows **1,500 บาท** and that `GET /api/v1/products` in devtools' Network tab shows `price_satang: 150000` (integer, not `1500` and not `1500.00`).
- [ ] **อัตราคอมมิชชั่น tab**: 6 seeded rules visible (2 products × 3 tiers), rates show as **3.00% / 5.00% / 8.00%** — not `300` / `500` / `800`.
- [ ] Try creating a commission rule for a product/tier combo that already has one, with an overlapping date range → should show a validation error, not silently succeed or 500.

## 3. Admin app — Super Admin (superadmin@example.test)

- [ ] Same catalog screen accessible.
- [ ] Creating a brand requires selecting/knowing a company (Super Admin isn't scoped to one) — confirm the request doesn't silently attach to the wrong company. (If the UI doesn't yet expose a company picker for Super Admin, that's a known gap — note it, don't treat it as a pass.)

## 4. Cross-tenant isolation (the one that actually matters most — BR-6)

This needs two companies. If the seeder only creates one (Thai Life), first use Super Admin to create a second company, then a Company Admin user for it (or do this check via API tooling like Postman/Insomnia instead of the UI):

- [ ] As Thai Life's Company Admin, try `GET /api/v1/brands/{id}` for a brand belonging to the *other* company → expect **404**, not 403, not 200.
- [ ] Try `PUT /api/v1/brands/{id}` on that same foreign brand → expect **404**.
- [ ] Try creating a product with a `brand_id` that belongs to the other company → expect **422** with a validation error on `brand_id`, not a 201.

## 5. Agent role (agent@thailife.test)

- [ ] Log into the **Agent Portal** (not Admin — agents don't get an Admin account in this app). Confirm there's currently no catalog UI exposed there (expected — out of scope until Referral submission is built).
- [ ] Via API tooling directly: `GET /api/v1/brands` as the agent → **200**, returns Thai Life's brands (read access confirmed).
- [ ] `POST /api/v1/brands` as the agent → **403** (create is admin-only).
- [ ] `GET /api/v1/commission-rules` as the agent → **403** (Agent never sees raw rates, per CommissionRulePolicy — this is a deliberate design call, flag it to ag-lead if you disagree).

## 6. Money/BR-3 spot check

- [ ] Anywhere a price or commission amount appears in the Admin UI, confirm there is never a decimal artifact from float math (e.g. `8900.000000000001`) — since everything is integer satang end-to-end, this should be structurally impossible, but verify once by eye.

## Known gaps at this stage (not bugs — out of scope per TASK-002)

- No `GET /cert-tiers` endpoint yet — the commission-rule form resolves cert tier IDs by scanning existing rules, which breaks for a brand-new company with zero rules. Real fix lands with the Academy phase.
- Seeded commission rates (3%/5%/8%) and product descriptions are placeholders (BR-7) — do not read these as real business decisions.
- No Super Admin company-picker UI yet when creating catalog rows cross-company.

## Sign-off

- [ ] Tested by: _____________  Date: _____________
- [ ] Result: ☐ Pass  ☐ Pass with known gaps above  ☐ Fail (list blocking issues)
