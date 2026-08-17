# ADR-033 — Post-payment service-access voucher, and shipping capture

- **Status:** accepted
- **Date:** 2026-08-16
- **Human decisions feeding this (2026-08-16):**
  1. "โบรชัวร์" means a **service-access voucher issued after payment**, analogous to a hotel
     voucher — not a marketing document. The customer presents it to redeem the service.
  2. Redemption happens **at a branch, by staff there — any branch, no restriction to a specific
     one.**
  3. **Usage quota and validity are configurable per product** (BR-7).
  4. **Validity counts from the payment date.**
  5. **Who may redeem is deferred to ADR-032 Phase 3** (custom company roles). This task does not
     create a new role or a `branches` entity.
- **Related:** ADR-017 (orders/payment), ADR-026 (pipeline templates — `delivery` /
  `service_appointment` stages), ADR-032 (permission architecture), BR-3, BR-4, BR-6, BR-7,
  CLAUDE.md §6 (audit log).

---

## 1. Context — what exists today, confirmed by audit

- `orders.public_token` is a 40-char unguessable token with **no expiry**, live before and after
  payment. `/pay/{token}` (`PublicPaymentController`, unauthenticated) is the one page a customer
  keeps a working link to, forever. This is the natural home for a voucher — no new delivery
  channel is needed for the MVP.
- Email does not work (`MAIL_MAILER=log`), `Client` is not `Notifiable`, no SMS/LINE integration
  exists. The pay-token page is genuinely the only reliable channel today.
- `qrCode.ts` (frontend Agent Portal) already generates QR codes; the same approach is reused for
  the frontend-admin voucher-redeem lookup screen, or the voucher can simply be read as text/code
  — no new QR library needed.
- **No `branches` table exists.** `referrals.branch` is a plain nullable string, and
  `StoreProductShareCheckoutRequest` deliberately never asks a self-serve customer for one
  (2026-08-08 ruling — "a customer cannot know which branch they are buying through"). That
  ruling is **about the sale**, not about redemption, and does not conflict with this ADR.
- `products` has no physical/digital distinction and no quota/validity config. `orders` has no
  shipping-address field. The closest existing precedent for transaction-time shipping capture is
  `reward_redemptions.shipping_*` (TASK-042) — captured at the moment of the transaction, never
  pulled from a stored profile.
- `PipelineStage::Delivery` / `ServiceAppointment` / `FollowUp` (ADR-026) are enum cases only; no
  seeded template uses them and no code reacts to them. This ADR does not wire them — see §5.

## 2. Decision

### 2.1 No `branches` table, no new role — yet

Human decision 2 ("สาขาไหนก็ได้") means redemption enforces **no branch-matching rule at all**.
Since nothing is enforced, a structured `branches` entity would model a restriction that does not
exist. **`redeemed_at_branch` is a plain nullable string on the redemption row** — the same
treatment `referrals.branch` already has, for the same reason (BR-7: don't build structure a rule
doesn't need). If a future rule needs branches to be real, first-class entities (staff assigned to
one, inventory per one), that is a new ADR, not a retrofit of this table.

Human decision 5 means **no new role is created in this task.** Redemption is gated behind a new
`Ability::VoucherRedeem` case, wired through the resolver TASK-185/186 already built — **not** a
bespoke role check. The interim role→ability grant is `company_admin` and `super_admin` (the same
tier that already redeems bank-slip payments today). When ADR-032 Phase 3 ships custom roles, an
Admin can narrow `voucher.redeem` to a leaner role without touching the redemption endpoint or its
tests — the ability already exists, only the grant changes. This is precisely the seam Phase 1 was
built to leave open (ADR-032 §2.4).

### 2.2 Voucher data model

A new table, `order_vouchers`, one row per **paid** order (1:1, created inside
`OrderService::confirmPayment()`'s existing transaction — mirrors the `$alreadyClosed` idempotency
guard already there so a re-confirm never mints a second voucher):

| Column | Purpose |
|---|---|
| `order_id` | FK, unique — one voucher per order |
| `code` | Unguessable redemption code (same `Str::random` treatment as `public_token`) |
| `usage_quota` | Snapshot of `product.voucher_usage_quota` at issuance — nullable = unlimited |
| `used_count` | Starts at 0 |
| `expires_at` | `order.paid_at + product.voucher_validity_days` — null if the product has no validity window |
| `status` | `active` / `exhausted` / `expired` (computed, not a source of truth stored independently of quota+expiry — avoid a second predicate that can drift, same lesson as `ClosedDealPredicate`) |

Each redemption writes `voucher_redemptions` (`order_voucher_id`, `redeemed_by_user_id`,
`redeemed_at_branch` nullable string, `redeemed_at`) — an audit trail, not a soft-editable log
(same immutability spirit as BR-4's ledger, though this is not commission money).

**Snapshotting at issuance, not reading `product` live at redemption time**, for the same reason
`orders.amount_satang` snapshots price (ADR-017): a product's quota/validity may change after
sale, and a voucher already issued must not retroactively change terms on a customer who already
paid.

### 2.3 Product config (BR-7)

Two new nullable columns on `products`: `voucher_usage_quota` (int, null = unlimited),
`voucher_validity_days` (int, null = never expires). Admin-editable on the product form — never
hardcoded, per BR-7 exactly as CLAUDE.md §8 rule 2 requires.

### 2.4 Delivery channel — reuse `/pay/{token}`, no new channel

The voucher renders as a new section on the **existing** public pay page, visible once
`order.status = paid`: the code, a QR encoding it, quota remaining, and expiry. No email, no SMS,
no new token, no new page. This is the direct consequence of §1's audit — building a second
delivery channel when a working one already reaches the customer would be solving a problem that
does not exist.

### 2.5 Shipping — a separate concern, captured once, through the same door

`products.requires_shipping` (boolean, admin-set, default false). `orders` gains
`shipping_recipient_name`, `shipping_phone`, `shipping_address` (nullable, same shape as
`reward_redemptions.shipping_*`) — captured **at the point of paying**, not pulled from
`clients.address`, for the same reasoning TASK-042 already established: a delivery destination is
a fact about this transaction, not the customer's permanent profile.

**Collected through the pay page, not a second form.** An order today can be created two ways
(agent "เก็บเงินเลย", or self-serve product-share checkout) and neither collects an address. Adding
the field to both creation paths would duplicate the same validation twice — instead, the **one**
place every order already funnels through regardless of origin is `/pay/{token}`, so the shipping
form (when `requires_shipping`) is collected there, submitted together with the payment slip. One
door, same principle this codebase has enforced repeatedly this sprint (TASK-176/177).

## 3. What this ADR deliberately does not do

- **Does not wire `PipelineStage::Delivery`.** A voucher and a shipping address are captured
  regardless of which pipeline template a product uses; whether "จัดส่ง" ever becomes a real
  pipeline step for a given product is an unrelated, still-open decision (ADR-026 §5) and is not
  forced by this ADR.
- **Does not build a branches entity, staff accounts, or a new role.** Deferred to ADR-032 Phase 3
  by human decision.
- **Does not build email/SMS delivery.** Deferred until reuse of the pay-token page proves
  insufficient in practice.

## 4. Consequences

**Accepted:**
- Any Company Admin or Super Admin can redeem any voucher of their own company at any "branch" —
  intentionally unrestricted, matching human decision 2. If this proves too permissive once real
  usage starts, narrowing it is a Phase-3 role change, not a rewrite.
- `redeemed_at_branch` is free text with no registry — reporting "redemptions by branch" will show
  whatever staff typed, with the same drift risk `referrals.branch` already carries. Accepted for
  the same reason it was accepted there.

**Open, to settle only if it becomes relevant:**
- Whether `PipelineStage::Delivery`/`ServiceAppointment` should ever automatically fire from
  voucher/shipping state — not decided, not needed for this ADR.

## 5. Alternatives considered

- **A `branches` table now.** Rejected: nothing in the confirmed requirements enforces
  branch-matching; building the entity ahead of a rule that needs it repeats the mistake
  ADR-026 explicitly avoided with pipeline stages ("a business value... lives in config, not in
  code" — here, in this case, not even config is needed yet).
- **A new customer-notification channel (email/SMS) for voucher delivery.** Rejected for the MVP:
  the pay-token page already reaches the customer with zero new infrastructure; building a second
  channel before the first is even used would be premature.
- **Reading `product.voucher_usage_quota`/`voucher_validity_days` live at redemption time instead
  of snapshotting.** Rejected: breaks the "customer gets what they were quoted" invariant
  ADR-017/TASK-136 already established for price.
