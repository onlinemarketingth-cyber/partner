# ADR-017: Order & Payment Collection (payment link + bank transfer / PromptPay + slip)

- **Date:** 2026-07-24
- **Status:** Accepted — human-confirmed 2026-07-24 (3 decisions below). Migrations + tests run by the human (sandbox has no PHP).
- **Author:** ag-lead
- **Related:** CLAUDE.md §4.3 (pipeline state machine), BR-3 (satang), BR-4 (immutable commission ledger), BR-7 (config not hardcoded), §5 (multi-tenant), §6 (PDPA / private files). ADR-013 (Client File), TASK-047 (sale price snapshot). TASK-054.

## Context

The human wants an **order/purchase system**: an agent creates an order for a client and either **sends the client a payment link** or collects a **bank transfer** — the client pays and uploads a slip; someone verifies it and the sale is "closed" (commission earned). Plus **seed example orders + commission** for `agent@thailife.test`.

## Decisions (human-confirmed 2026-07-24)

1. **Payment method scope = bank transfer + PromptPay QR + slip upload; NO external gateway** this phase. The "payment link" is an **in-app public payment page** (`/pay/{token}`) showing the amount, the company's bank details + a PromptPay QR, and a slip-upload form. No third-party checkout, no credentials. (Schema keeps nullable `payment_reference` room for a future gateway, but none is wired.)
2. **Order is bound to a Referral / Pipeline; paying = Complete Payment (reuse BR-4).** An order does NOT introduce a second commission path. Verifying payment calls the existing `PipelineService::advance()` to move the referral to **Complete Payment**, which already fires `CommissionService::recordForReferral()` (BR-4, idempotent). To respect §4.3 "no skipping", confirming is only allowed when the referral sits at **Finish 1st Doctor Meeting** (the stage immediately before Complete Payment — the domain-correct moment to collect payment). Earlier stages are blocked with a clear message (no fabricated intermediate stage logs).
3. **Seed a mixed set (~5–10) of orders (pending + paid) + commission** under `agent@thailife.test`.

## Data model

- **`companies`** gains nullable payment-config columns (BR-7 admin config, never hardcoded): `payment_promptpay_id`, `payment_bank_name`, `payment_bank_account_number`, `payment_bank_account_name`. Seeded for Thai Life; editable by Company Admin.
- **`orders`**: id, `company_id` (FK, TenantScope), `referral_id` (FK → the sale being paid), `client_id`, `agent_id`, `product_id` (denormalized snapshots for reporting), `order_number` (human ref, unique per company), `public_token` (unguessable 40-char, the share link), `amount_satang` (BR-3 — snapshot of `product.price_satang` at creation; commission still uses CommissionService's own promo-aware resolution as source of truth), `payment_method` (enum `bank_transfer` | `promptpay`), `status` (enum `pending` → `awaiting_verification` → `paid`; or `cancelled`), `slip_path` (private disk, nullable), `payment_reference` (nullable, future gateway), `paid_at` (nullable), `verified_by_user_id` (nullable), timestamps. Indexed (company_id, agent_id, status).

### Status lifecycle
```
pending  ──customer uploads slip──▶ awaiting_verification ──agent/admin verify──▶ paid
   └──────────────────── cancel ───────────────────┴─────────────▶ cancelled
```
`paid` is terminal and triggers the referral advance → BR-4 commission (once; idempotent).

## Security / privacy
- **Public page** (`GET /pay/{token}`, `POST /pay/{token}/slip`) is unauthenticated but token-gated + throttled (same pattern as the affiliate `/l/{token}` public routes). It exposes ONLY: product name, amount, company payment details, and accepts a slip image. It never exposes client PDPA data beyond the client's display name.
- **Slip files** live on the **private** disk, tenant-scoped path, downloadable only via an access-checked authenticated endpoint (§6 / §5.6 — same contract as `ClientDocument`), never a public URL.
- All authenticated order endpoints are TenantScope + Policy gated: an **agent** sees/creates only their own orders; a **Company Admin** sees their company's; cross-tenant/cross-agent access → 403/404.
- PromptPay QR: backend returns the EMVCo payload string (deterministic from `payment_promptpay_id` + amount); the frontend renders the QR. No secrets involved.

## Commission linkage (BR-4, unchanged)
Confirming a paid order advances the referral to Complete Payment via `PipelineService::advance()`. Commission is computed by the existing `CommissionService` (its own promo-aware price resolution stays the source of truth); the order's `amount_satang` is the customer-facing figure only. No new commission math, no edits to the immutable ledger.

## Consequences
- Reuses the pipeline + commission engine unchanged; the order layer is purely payment collection + a shareable page.
- Constraint: an order can only be **confirmed** when its referral is at Finish 1st Doctor Meeting. `// TODO: CONFIRM` — whether admins should be able to collect payment earlier in the funnel; deferred, not guessed.
- Promo-aware order pricing (matching CommissionService exactly) is a follow-up; this phase snapshots list price for the customer-facing amount.
- Future payment-gateway integration can slot into `payment_reference` + a webhook without reshaping the table.
```
```
