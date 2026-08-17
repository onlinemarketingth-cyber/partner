# TASK-193 — backfill vouchers for legacy paid orders (backend only)

- **Owner:** ag-lead (spec) → ag-dev (backend, console command only) → ag-qa
- **Date:** 2026-08-17
- **Human:** confirmed via `php artisan tinker` that order `ORD-3HYA3EXB` (paid 2026-08-13, before
  TASK-189 shipped 2026-08-16) has `voucher: NO` — exactly the documented "older/legacy paid
  orders predate this feature and may have none" gap (`PublicOrderResource.php` comment). Asked
  for a one-time backfill covering **every** paid order missing a voucher, not just this one, and
  explicitly said **not** to re-send the payment-confirmation email/notification for backfilled
  orders — those already happened (or didn't) for real at the time; this is a data-repair pass,
  not a new payment event.
- **Related:** ADR-033 §2.2/§2.4, TASK-189 (`OrderVoucherService::issueFor()`), TASK-190 (the
  notification/email this task must NOT re-trigger).

---

## 1. Scope

**In:** one new idempotent Artisan console command (suggested name
`vouchers:backfill-legacy-paid-orders`, ag-dev may rename if there's an existing naming
convention for one-off commands in this codebase — check `app/Console/Commands/` first) that:

1. Finds every `Order` where `status === OrderStatus::Paid` AND `voucher` relation is null.
   Console commands run with no authenticated user, so `TenantScope` (`apply()`, see
   `app/Models/Scopes/TenantScope.php`) is a no-op when `auth()->user()` is null — the query
   naturally sees orders across every company already; **do not** add a manual company filter, and
   don't attempt to fake an authenticated Super Admin — the scope already does the right thing.
2. For each one, calls the **existing** `OrderVoucherService::issueFor($order)` — do not
   re-implement code generation/quota/expiry logic a second way. Note `issueFor()` reads
   `$order->paid_at` for the expiry snapshot calculation — historical orders already have this
   column set from when they were originally confirmed, so the expiry backfilled today is
   calculated as if issued at the time of that order's own original payment, not today's date
   (matches the "one door" reasoning already in the service's docblock).
3. Wraps **each order's** voucher creation in its own `DB::transaction()` (not one transaction for
   the whole batch) — one bad row must not roll back every other successfully-backfilled order.
4. **Does not** touch `NotificationType::OrderPaymentConfirmed` or `OrderPaymentConfirmedMail` in
   any way — this command must not import or call anything from that path. This is the human's
   explicit instruction (§ above) and also the correct read of TASK-190's own spec: that
   notification/email is scoped to the live `confirmPayment()` event, not a historical repair.
5. Supports a `--dry-run` flag: reports exactly what *would* be backfilled (order numbers + count)
   without writing anything.
6. Without `--dry-run`, prints a summary: total paid orders scanned, count that already had a
   voucher (skipped, unchanged), count successfully backfilled, and any order_number + error
   message for a row that failed (a single failure must not stop the command from continuing to
   the next order — catch per-order, don't let one throw abort the whole run).
7. Re-running the command after a successful run must be a no-op (every previously-paid order now
   has a voucher, so the "no voucher" query returns nothing) — this is naturally true from step 1's
   query condition, just confirm it with a test.

**Out:** any change to `OrderVoucherService`, `OrderService::confirmPayment()`, the notification/
email path, or any frontend. This is a standalone maintenance command only.

## 2. Verification

- Feature test: seed 3 paid orders with no voucher + 1 paid order that already has one + 1
  unpaid order. Run the command. Assert: the 3 voucherless paid orders now have exactly one
  voucher each (code, quota, expiry match what `issueFor()` would produce), the order that
  already had one is untouched (still exactly one voucher, same code — not replaced), the unpaid
  order still has none.
- `--dry-run` test: same seed, assert zero `OrderVoucher` rows are created, and the reported count
  matches the 3 voucherless paid orders.
- Assert `NotificationType::OrderPaymentConfirmed` notifications table row count is unchanged
  before/after running the command (proves no notification fired).
- Assert no mail was sent (use Laravel's `Mail::fake()` / assert nothing queued/sent) during the
  command run.
- Re-run the command a second time after a successful first run; assert it reports 0 backfilled
  (idempotency).
- A single order engineered to fail mid-`issueFor()` (e.g. mock/force an exception) must not
  prevent the other orders in the same batch from being backfilled successfully — assert the
  command's exit behavior and summary reflect a partial failure without losing the successes
  already committed.
- `pint`.

## 3. Definition of Done

CLAUDE.md §9, plus: idempotent (safe to re-run), no notification/email side effect, one order's
failure doesn't block or roll back the others, and the backfilled voucher data is produced by the
same `OrderVoucherService::issueFor()` code path as a live payment confirmation — not a second,
divergent implementation.
