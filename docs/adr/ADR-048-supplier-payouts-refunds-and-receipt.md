# ADR-048 — Supplier payouts: no self-withdrawal, refunds, receipt-based release

- **Status:** Accepted
- **Date:** 2026-09-27
- **Decided by:** the owner (KreangYot), answering four questions raised by the 2026-09-27 audit of the supplier system
- **Relates to:** the supplier system built 2026-09-16/17. It had no ADR before this one; the owner's original rulings are quoted in the code docblocks.

## Context

An audit of the supplier (คู่ค้า) system found two groups of problems.

The first group was open questions only the owner could answer. They are listed below with his answers.

The second group was defects that needed no business decision:
- a refund never touched the supplier ledger;
- clearing a supplier's terms while its products were on sale made the next payment confirmation fail;
- raising a payout took no lock;
- changing a deal's release trigger stranded rows sold under the old one;
- there was no audit log on any supplier money action;
- a raised payout could not be cancelled;
- the supplier's own statement showed blank order numbers;
- a payout could be raised through the API with no bank account.

## The owner's answers

| Question | Answer |
|---|---|
| Should suppliers request withdrawals themselves? | **No.** ("ไม่ได้") |
| A customer is refunded after we already paid the supplier | **Deduct it next time.** ("หักครั้งถัดไป") |
| Commission that arises after the sale (renewal, binary, promotion bonus) | **Not deducted from the supplier.** The supplier's amount is fixed when the sale is recorded. |
| "Pay when delivered" deals | **The recipient confirms receipt.** If neither the agent nor the customer confirms within 15 days after the supplier marks the order shipped, the money becomes payable anyway. The 15 days is **one platform-wide value**. |

## Decision

### 1. No supplier self-withdrawal

- `SupplierPayoutService::open()` only ever creates a company payout, opened Approved.
- The supplier-request path is removed: `approve()`, `reject()` and the minimum-withdrawal check. So are the minimum-withdrawal field in the supplier form and the `min_withdrawal_satang` validation rule. The column stays until the legacy-column migration drops it.
- A raised payout can now be **cancelled** with a required reason (`POST /supplier-payouts/{id}/cancel`). Its rows return to the payable pool untouched.

### 2. Refunds

A refund writes a **refund row** in `supplier_settlement_ledger`:
- `entry_kind = refund`;
- `reverses_ledger_id` points at the sale row;
- `amount_satang` is the sale's amount negated.

The sale row is never edited. Uniqueness moves from `order_id` to `(order_id, entry_kind)`, so one sale row and one refund row per order.

When the refund row is released:
- **Sale already payable or paid:** the refund row is payable immediately. It comes off the supplier's **next** payout, including when the sale is sitting in a payout that has not been transferred yet.
- **Sale not yet payable:** the refund row waits with the sale. The pair nets to zero in "not yet payable". A refunded order is never auto-received.

Other effects:
- The refund is written inside `CommissionReversalService::refundOrder()`'s transaction. The `order.refunded` audit entry records `supplier_satang_reversed`.
- **Withholding tax:** a refund row lowers the tax base at its own rate. A shortfall row still does not. A group whose refunds exceed its sales withholds nothing.

### 3. Commission after the sale

This needs no change; it is recorded here as the rule. `commission_satang_at_time` is summed once, when the sale is recorded, and the supplier amount is fixed then. Renewal commission, binary matching and promotion bonuses written later are the platform's cost.

### 4. Receipt releases the money, not shipping

- The supplier's "shipped" click only records the shipment. It no longer releases anything. Until now the payee was reporting the event that paid them.
- **Receipt** releases an `on_delivered` row. The stored value keeps its name; its label becomes "เมื่อผู้รับได้รับสินค้า". Receipt can be confirmed three ways:
  - by the **selling agent**: `POST /orders/{order}/receipt`. Policy `OrderPolicy::confirmReceipt` allows the order's own agent only; admins and partners are refused.
  - by the **customer** on the payment link: `POST /pay/{token}/receipt`, throttled like the slip.
  - **automatically** once `supplier_platform_settings.auto_receive_days` have passed since `shipped_at`.
- Whoever confirms first counts. A second confirmation is refused.
- `orders` gains `received_at`, `receipt_confirmed_via` (`agent` / `customer` / `auto`) and `received_by_user_id`.
- Each confirmation writes an `order.receipt_confirmed` audit entry.
- **The window:**
  - It is one platform value, seeded at 15 and edited by a Super Admin on จัดการคู่ค้า (`GET/PUT /supplier-settings`, minimum 1 day).
  - Changes are audited (`supplier_settings.updated`).
  - If the row is missing, nothing auto-confirms. There is no guessed default.
- **Auto-confirm runs in three places:**
  - a daily scheduled job, `suppliers:auto-confirm-receipts`;
  - whenever a supplier balance is read (admin payout screen, supplier portal);
  - whenever a payout is raised.

  The last two exist because production has not reliably had its scheduler running. Closed companies are skipped, as with every scheduled money job (ADR-047).

### 5. Correctness fixes from the audit

- **The release trigger is snapshotted** on each ledger row (`release_trigger_at_time`). Release matches the row's own trigger, not the deal's current one. Existing rows are backfilled from their supplier's current trigger.
- **A live deal cannot be left half-set.** Editing a supplier is refused (422) when it would leave an on-sale product without a trigger or without GP. The new `SupplierService` enforces this. Product listing now also requires the supplier's trigger even when the product carries its own GP.
- **Payout raising locks the supplier row**, so a double-click cannot reserve the same rows twice. It also checks the bank account on the server.
- **Audit log** (`company_id` null, since a supplier is not a tenant):
  - `supplier.created` and `supplier.updated`, covering money fields only, old → new;
  - `supplier_payout.opened`, `supplier_payout.transferred` and `supplier_payout.cancelled`.
- **Order numbers on the supplier's statement.** The order relation is loaded without TenantScope, because a partner has no tenant.

## Consequences

- **New migrations:**
  - `2026_10_07_090000` (ledger);
  - `2026_10_07_090100` (orders);
  - `2026_10_07_090200` (settings; seeds 15).

  Deploy must run `php artisan migrate`.
- `uat:reset` keeps `supplier_platform_settings` as config.
- **The row lock is not proven by a test.** Concurrency cannot be reproduced in the SQLite test suite. It mirrors the commission flow's lock.
- **Still open, and not part of this ADR:**
  - supplier notifications (new order to ship; payout sent);
  - a CSV export of payouts. An old code comment claimed one existed; it has been corrected;
  - paging on the supplier lists beyond 25–50 rows;
  - the supplier field in the product editor locks even when the product has no sales;
  - dropping the legacy supplier columns (the held-back migration).
- **Tests:**
  - backend: `SupplierReceiptAndRefundTest` (24 tests), plus updated `SupplierPayoutTest`;
  - frontend: `SupplierAdr048.spec.ts` (admin) and `SupplierReceiptConfirm.spec.ts` (portal).

  Each guard was mutation-checked: removing it fails a test.
