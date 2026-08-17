# TASK-189 — Post-payment voucher (redeemable service-access document) + shipping capture

- **Owner:** ag-lead (spec) → ag-dev (phases A–D backend) → ag-ui (phases E–F frontend) → ag-qa
- **Date:** 2026-08-16
- **Human:** *"โบรชัวร์... หมายถึง เอกสารเข้าใช้บริการ หลังชำระเงินแล้ว แบบเดียวกับ vocher โรงแรม"* +
  answers: redemption at any branch, quota/validity per-product, validity from payment date, staff
  role deferred to Phase 3.
- **Related:** ADR-033 (read it first — it has the reasoning this spec assumes), ADR-017, ADR-032,
  BR-3, BR-4, BR-6, BR-7, CLAUDE.md §5, §6.

---

## 1. Scope

**In:** a voucher issued automatically when an order is paid; a redemption endpoint gated by a new
ability; the voucher rendered on the existing public pay page; per-product quota/validity config;
shipping-address capture for physical products, collected on the same public pay page.

**Out (ADR-033 §3):** any `branches` table or staff role (deferred to ADR-032 Phase 3), any new
notification channel, any wiring of `PipelineStage::Delivery`.

## 2. Phase A (ag-dev) — schema

**A1.** Migration: `order_vouchers` — `order_id` (FK, unique), `code` (string, unique,
`Str::random(40)`, same treatment as `orders.public_token`), `usage_quota` (nullable unsigned int),
`used_count` (unsigned int, default 0), `expires_at` (nullable timestamp), timestamps. No `status`
column — computed from quota/expiry, not stored (ADR-033 §2.2, avoid a second predicate that can
drift from the two source facts).

**A2.** Migration: `voucher_redemptions` — `order_voucher_id` (FK), `company_id` (FK, TenantScope —
every business table per §5 rule 1), `redeemed_by_user_id` (FK users), `redeemed_at_branch`
(nullable string, free text — **not** a new `branches` FK, per ADR-033 §2.1), `redeemed_at`
(timestamp), `created_at` only (no `updated_at` — a redemption is never edited, same immutability
spirit as the commission ledger).

**A3.** Migration: add to `products` — `voucher_usage_quota` (nullable unsigned int),
`voucher_validity_days` (nullable unsigned int), `requires_shipping` (boolean, default false).

**A4.** Migration: add to `orders` — `shipping_recipient_name`, `shipping_phone` (string,
nullable), `shipping_address` (text, nullable) — same shape as
`reward_redemptions.shipping_*` (TASK-042).

**A5.** Models + relations: `OrderVoucher belongsTo Order`, `hasMany VoucherRedemption`;
`VoucherRedemption belongsTo OrderVoucher`, `belongsTo User` (redeemer). Add `Order::hasOne(OrderVoucher)`.

## 3. Phase B (ag-dev) — issuance

**B1.** `OrderVoucherService::issueFor(Order $order)`. Called from inside
`OrderService::confirmPayment()`'s existing `DB::transaction` (`OrderService.php:189-203`), **only
when `! $alreadyClosed`** — the exact same idempotency guard that already stops a re-confirm from
double-firing BR-4 commission, reused here so a re-confirm cannot double-issue a voucher either.

**B2.** Issuance snapshots `usage_quota` from `product.voucher_usage_quota` and computes
`expires_at = $order->paid_at->addDays($product->voucher_validity_days)` when set, else null
(ADR-033 §2.2/§2.4 — snapshot, never read the product live at redemption time).

**B3.** Add a computed `status` accessor on the model (`active` / `exhausted` / `expired`) — not a
column. `exhausted` when `usage_quota !== null && used_count >= usage_quota`; `expired` when
`expires_at !== null && expires_at->isPast()`; else `active`.

## 4. Phase C (ag-dev) — redemption

**C1.** New `Ability::VoucherRedeem = 'voucher.redeem'` in `App\Enums\Ability`, with a provenance
docblock (this task, not an existing call site — the first ability TASK-186 onward adds that
*isn't* derived from a pre-existing check; say so explicitly in the docblock rather than pretend a
prior site existed).

**C2.** `PermissionResolver::ROLE_ABILITIES` — grant `Ability::VoucherRedeem` to
`UserRole::CompanyAdmin` and `UserRole::SuperAdmin`. **Not** Agent. This is the interim grant ADR-033
§2.1 describes; when Phase 3 ships, narrowing this to a custom role is a config change, not a
redesign of this endpoint.

**C3.** `VoucherRedemptionService::redeem(string $code, User $actor, ?string $branch): OrderVoucher`.
Look up the voucher by `code` **without TenantScope** on the query itself (same pattern as
`PublicPaymentController::resolve()`), then explicitly check `$voucher->order->company_id ===
$actor->company_id` (Super Admin excepted) — same shape as the tenant check TASK-186 preserved
elsewhere, not TenantScope, because this is an authenticated Admin-app endpoint reading a voucher
that has no `company_id` column of its own (it hangs off `order`).

Refuse (422, distinct Thai messages) when: not found, `status !== 'active'` (name which — exhausted
vs expired, the customer/staff needs to know which), cross-tenant (404, matching the IDOR posture
of every other cross-tenant lookup in this codebase, §5 rule 5).

On success, inside a transaction: increment `used_count`, write the `voucher_redemptions` row with
`redeemed_at_branch` taken verbatim from the request (nullable — "สาขาไหนก็ได้" means it is
descriptive, not a foreign key to validate against).

**C4.** Controller + route: `POST /api/v1/vouchers/redeem` (authenticated, `auth:sanctum`), body
`{code, branch?}`. Gate via `Gate::authorize(Ability::VoucherRedeem)` — same wiring pattern
TASK-186 used for the other 29 sites, confirm the denied-actor status code is 403.

**C5.** A lookup endpoint, `GET /api/v1/vouchers/{code}`, same ability gate, so the redeem screen
can show the order/product/customer name **before** the staff member commits to redeeming (avoids
a blind POST). Response via a Resource that exposes only what redemption staff need — not the
customer's full PDPA record (§6).

## 5. Phase D (ag-dev) — shipping capture

**D1.** Extend `SubmitSlipRequest` (`backend/app/Http/Requests/Order/` — find the exact file) to
accept `shipping_recipient_name`, `shipping_phone`, `shipping_address`, **required when
`$order->product->requires_shipping`, otherwise absent/ignored**. This is the "one door" ADR-033
§2.5 describes — collected once, at the point of paying, regardless of whether the order was agent-
created or self-serve checkout.

**D2.** `OrderService::submitSlip()` — persist the three shipping fields onto the order alongside
the existing slip-path write, when present. Do not require them to already be set before slip
upload is allowed unless `requires_shipping` — the field is genuinely optional for a
non-physical product.

**D3.** `PublicOrderResource` / `PublicPaymentController::show()` — expose `requires_shipping` and
the current shipping fields (so the frontend pay page knows whether to render the address form and
can pre-fill on a re-visit).

## 6. Phase E (ag-ui, Agent Portal) — public pay page

**E1.** `/pay/{token}` (`PublicPaymentView.vue` or wherever it lives — find it) — once
`order.status === 'paid'` and a voucher exists, render a new section: the code, a QR (reuse
`qrCode.ts`), `used_count / usage_quota` (or "ไม่จำกัด" if quota is null), and expiry (or
"ไม่มีวันหมดอายุ" if null).

**E2.** When `product.requires_shipping` and the order is not yet paid, render the shipping-address
form **alongside the existing slip-upload step**, submitted in the same request as D1's extended
validation. Client-side required only when the flag is true.

## 7. Phase F (ag-ui, Admin) — config + redemption screen

**F1.** `ProductEditView.vue` — two new fields: usage quota and validity days (nullable = unlimited
/ never expires, say so in the UI, not just accept empty), and the `requires_shipping` toggle.
BR-7 — these must be visibly admin-editable, not just accepted by the API.

**F2.** New screen: voucher redemption lookup. A single code input (or camera QR scan if
straightforward — text input is an acceptable MVP, do not block on camera access) → calls C5's
GET → shows customer/product/quota-remaining/expiry → a "ตัดสิทธิ์" button with an optional
free-text branch field → calls C4's POST → shows the result. Reachable only to Company Admin /
Super Admin per C2 (a route guard mirroring the ability, plus the backend is the real gate per
§5's existing pattern).

## 8. Verification

- Mutation-check B1's idempotency guard: force a double-confirm, prove only one voucher exists.
- Redemption at/over quota is refused; redemption after `expires_at` is refused; cross-tenant
  redemption 404s.
- A test proving `Ability::VoucherRedeem` denies Agent (403) and allows Company Admin/Super Admin.
- Shipping fields are required server-side when `requires_shipping` and absent-tolerated otherwise
  — test both.
- `php artisan test` (via the `/tmp` copy method prior tasks used) + `pint`.
- `npx vue-tsc --noEmit`, `npx eslint src`, `npm run build` in both frontends (no `npm install`/
  `npm ci` in the sandbox — read-only commands only).
- Live browser check of both new screens.

## 9. Definition of Done

CLAUDE.md §9, plus: a paid order always has exactly one voucher, quota/validity are admin-config
per product (no hardcoded number), redemption is gated by the new ability (not a raw role check —
this task must not add a 30th `abort_unless`), shipping is captured once through the public pay
page for both order-creation paths, and nothing in this task builds a `branches` table, a new
role, or a new notification channel.
