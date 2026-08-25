# TASK-139 — Omise payment gateway: what was built, and what is still yours to do

ADR-027 §3. Written 2026-08-23 by ag-lead.

---

## 1. What is finished

| Phase | Scope | State |
|---|---|---|
| 1 | Per-company credentials, encrypted; provider abstraction; Super-Admin-only settings API | done, tested |
| 2 | `POST /pay/{token}/charge`; pay page card form via Omise's hosted form | done, tested |
| 3 | `POST /webhooks/payments/{provider}/{company}`; signature verification; idempotency | done, tested |
| 4 | Super Admin settings screen; admin payments screen shows gateway state | done |
| 5 | **A real test-mode transaction against Omise** | **NOT DONE — needs real test keys** |
| 6 | A real live-mode transaction, ~฿20, on your own card | **YOURS** |

Backend suite: 1,866 passing. Both frontends typecheck and lint clean.

---

## 2. The single most important thing on this page

**Nothing here has ever talked to Omise.**

Every test in `GatewayChargeAndWebhookTest` fakes the network. They prove
*our* logic — that a forged webhook is refused, that a duplicate writes one
commission row, that an amount mismatch is rejected. They cannot prove:

- that Omise's signature header is really `X-Omise-Signature`
- that it signs the **raw request body** with the webhook secret, HMAC-SHA256
- that the charge response is shaped `{id, status, amount, failure_message}`
- that `metadata` comes back verbatim on webhook events
- that `OmiseCard.open()` takes the options this code passes it

Each of those is read from Omise's documentation and **assumed**. Phase 5
exists to convert them from assumptions into facts, and until it runs, the
correct summary of this work is "built and self-consistent", not "working".

If any assumption is wrong, the symptom is specific and predictable:

| Wrong assumption | What you will see |
|---|---|
| Signature header name / scheme | Every webhook returns 401. Card charges still succeed. |
| Charge response shape | Charge returns 422 with an odd message, or the order is not confirmed. |
| `metadata` not echoed | Webhooks return `"unmatched"` in the response body. |
| `OmiseCard` option names | The card form does not open, or opens with the wrong amount. |

---

## 3. Deploy order — this matters

The gateway work touches `orders`, and there are already **9 unpushed
commits** before it. Deploy in two steps, not one:

```bash
# Step 1 — the existing backlog, on its own, so a problem is attributable
git push origin main
bash scripts/deploy.sh
# verify the app is healthy before continuing

# Step 2 — the gateway work
git push origin main
bash scripts/deploy.sh
php artisan migrate     # on the HOST, not local
```

Two migrations run:

- `create_company_payment_gateway_settings_table` — new table, plus
  `companies.payment_provider` defaulting to `'manual'`
- `add_gateway_columns_to_orders_table` — `orders.payment_provider`,
  `gateway_mode`, `gateway_charge_id` (UNIQUE)

**Existing orders and companies are unaffected.** Every company defaults to
`manual`, which is exactly what they do today, and the manual pay page reads
`companies.payment_promptpay_id` as it always has. There is no backfill and
nothing to undo.

---

## 4. Phase 5 — the test-mode run (do this before Phase 6)

You need an Omise account in **test mode**. Keys start `pkey_test_` /
`skey_test_`.

1. Admin console → ช่องทางรับชำระเงิน. Pick the company in the header first —
   the screen refuses to render until you do, on purpose.
2. Paste the public key, secret key, and webhook signature secret.
   Leave **โหมดใช้งานจริง (Live) OFF**.
3. Save. The system calls Omise's `/account` and shows **which account
   answered, by email**. Read it. A green tick cannot tell you that you have
   just connected the wrong company's Omise; that email can.
4. Copy the Webhook URL shown on the same card into your Omise dashboard's
   webhook settings.
5. Click เปิดใช้งานช่องทางนี้.
6. Create a normal order and open its `/pay/...` link.

Then run these six, in order:

| # | Do | Expect |
|---|---|---|
| 1 | Pay with test card `4242 4242 4242 4242`, any future expiry, any CVV | Order becomes ชำระแล้ว. **Exactly one** commission_ledger row. |
| 2 | Check the admin payments screen | The row shows "โหมดทดสอบ" and "ตัดบัตรสำเร็จแล้ว" |
| 3 | Re-send the same webhook event from the Omise dashboard | Still **one** ledger row. Response `{"message":"ok"}`. |
| 4 | New order; pay with declined card `4111 1111 1111 1140` | 422, Omise's own reason on screen, order untouched, no ledger row |
| 5 | `curl -X POST <webhook URL> -d '{"key":"charge.complete"}'` (no signature) | **401** |
| 6 | Try to press ชำระด้วยบัตร twice quickly on one order | The second is refused before Omise is called |

**Number 5 is the one that must not be skipped.** If it returns anything but
401, stop and tell me — it means anyone on the internet can mark orders paid
and write commission rows that cannot be un-written.

---

## 5. Phase 6 — the live run (yours alone)

I will not handle live keys and did not ask for them. When Phase 5 is clean:

1. Same screen, live keys (`pkey_live_` / `skey_live_`), **โหมดใช้งานจริง ON**.
   The system refuses a test key in live mode and a live key in test mode —
   both directions, deliberately.
2. Read the account email again.
3. Create a real ฿20 order and pay it with **your own card**.
4. Confirm in the Omise dashboard that the charge is there and settling.
5. Refund it from the Omise dashboard.

**Note on step 5:** refunding in Omise does *not* reverse the agent's
commission here. The webhook logs the refund and deliberately does nothing
else — reversing a BR-4 row is its own immutable entry with its own rules,
and a webhook must not make that decision. Use the existing refund flow if
the commission needs reversing.

---

## 6. Things I decided, that you may want to overrule

**A switched-off gateway kills old pay links.** If a company switches from
Omise back to manual, `/pay` links already sent stop offering a card and the
charge endpoint refuses. This fails closed: a company that switched has
decided to stop collecting that way. The cost is a customer holding a link
that no longer works, who must contact the seller. I chose safety; say if
you would rather old links kept working.

**A declined card does not cancel the order.** The customer will very likely
try another card within thirty seconds, and a cancelled order turns a retry
into a dead link.

**Shipping address is not collected on the card path.** The slip flow
captures it (ADR-033 §2.5); the card flow does not, because where those three
fields belong in a card form is a design decision, and guessing would put a
second, differently-validated copy of them in the codebase. If you sell
physical goods through the card path, this needs deciding before Phase 6.

**Test mode is visible to the customer.** The pay page says "โหมดทดสอบ — รายการ
นี้จะไม่มีการเรียกเก็บเงินจริง". Somebody about to type a card number is
entitled to know which kind of charge it is.

---

## 7. When money arrives and the sale does not close

The system claims the charge before confirming the order, and confirmation
can still refuse — its pipeline rule is about the *sale*, not the money.
Rather than retry-looping a webhook or telling a charged customer "payment
failed", it records the receipt and stops.

Those orders are **not** left to a log line:

- Admin → คำสั่งซื้อ / การชำระเงิน shows a red banner when any exist
- The last tab, ได้รับเงินแล้วแต่ยืนยันไม่สำเร็จ, lists them
- API: `GET /orders?needs_attention=1`
- Logs: `Gateway payment received but the order could not be confirmed`
  (level `critical`)

Expected count in normal operation: **zero**. If it is not zero, a customer
has been charged for a sale nobody closed.

---

## 8. Still unconfirmed from earlier work

Unrelated to Omise, still outstanding:

- Is `schedule:run` in cron on the host? (the notification email sweep needs it)
- Is production `MAIL_MAILER` configured?
