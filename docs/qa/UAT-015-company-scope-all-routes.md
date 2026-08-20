# UAT-015 — company scope across all 32 Admin routes

- **Covers:** ADR-038, TASK-208, TASK-209 (P1–P4)
- **Date:** 2026-08-19
- **Tester:** human / ag-qa — **first browser pass; nothing here has been clicked yet.**
  ag-lead verified compile (all SFCs, 0 template errors) and the backend suite (1603 passed) only.
- **Login needed:** a Super Admin **and** a Company Admin.

---

## 0. Pre-flight

```bash
cd backend && php artisan serve --host=admin.localhost --port=8010
cd frontend-admin && npm run dev
```

DevTools → Console must be clean of `ERR_CONNECTION_REFUSED` before starting.

## 1. The switcher itself

| # | Step | Expected |
|---|---|---|
| 1.1 | Log in as Super Admin, first time (clear `localStorage` first) | header button reads **ทุกบริษัท**, amber (human decision: default is ทุกบริษัท) |
| 1.2 | Click it | dropdown with a search box, "ทุกบริษัท (ดูอย่างเดียว)" + every company |
| 1.3 | Pick a company | button turns brand-navy with that company's name |
| 1.4 | Reload the page | **still that company** (persisted) |
| 1.5 | Navigate between 5 different pages | scope never resets |
| 1.6 | Log in as **Company Admin** | no dropdown at all — a plain grey label with their own company name |

## 2. Class B — screens that must follow the scope (17)

For **each** route below: switch company on the header and confirm the list content changes, and
that in ทุกบริษัท mode create/edit is blocked with the amber `CompanyScopeNotice`.

`home` · `agent-management` · `agent-roster` · `agent-approvals` · `agent-invite-links` ·
`client-management` · `client-file` · `referral-pipeline-management` · `commission-management` ·
`sales-team` · `agent-commission-summary` · `product-performance` · `agent-promotions` ·
`reward-center` · `announcements` · `gamification-config` · `voucher-redeem`

Spot-checks that matter more than the rest:

- **2.a `client-management` (PDPA, strict):** in ทุกบริษัท mode the page must show ONLY the notice —
  **no client rows at all**, and the Network tab must show **no `/clients` request**. This is
  stricter than every other screen, by decision.
- **2.b `commission-management`:** switch company → ledger rows change. A total must never mix two
  companies.
- **2.c `agent-roster`:** the roster walks every page (`fetchAllPages`). With a company scoped,
  Network should show `company_id=` on the `/users?...page=N` calls — not a client-side filter.
- **2.d `announcements` / `reward-center` / `gamification-config`:** with a company scoped, the list
  shows that company's rows **plus** platform-wide rows badged **ทั้งแพลตฟอร์ม**. Opening the create
  form pre-selects the scoped company, and "ทั้งแพลตฟอร์ม" is still selectable.

## 3. Class C — screens that must IGNORE the scope (5)

`company-management` · `catalog-management` · `mail-settings` · `policy-report` → *platform* tab ·
`profile`

Each must show the grey **"หน้านี้เป็นข้อมูลระดับแพลตฟอร์ม — ไม่ขึ้นกับบริษัทที่เลือกไว้ด้านบน"** badge,
and switching companies must change nothing on them.

`policy-report`'s other three tabs (audit / compliance / config) DO follow the scope — check both
behaviours on the same screen.

## 4. Class A regression (10, shipped in TASK-208)

`product-catalog` · `product-create` · `product-edit` · `academy-management` ·
`commission-plan-settings` · `theme-settings` · `video-settings` · `team-visibility-settings` ·
`commission-split-settings` · `policy-report`

- Switching companies refetches (products, brands, categories, banners)
- `product-edit` shows the blue "บริษัท: X" line; creating a product lands in the scoped company
- `theme-settings` / `video-settings` / `team-visibility-settings` edit the scoped company's row

## 5. Security — must pass before this ships (BR-6)

| # | Step | Expected |
|---|---|---|
| 5.1 | As Company Admin, in DevTools run `fetch('/api/v1/products?company_id=<another company id>')` | only **their own** company's products (the filter narrows, never widens) |
| 5.2 | Same for `/clients`, `/users`, `/commission-ledger` | same |
| 5.3 | As Super Admin scoped to company A, open a deep link to a product of company B | opens, and the page states company B (rule 4 — labelled, not blocked) |

5.1/5.2 are also covered by `CompanyScopeFilterTest`, but re-run them by hand: this is the one
thing in the whole task that could leak data across tenants.

## 6. Reporting

Per failure: route, step number, screenshot, and the Network entry (request URL + response) for the
call that misbehaved.
