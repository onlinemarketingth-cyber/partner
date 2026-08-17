# TASK-176 — Admin "รับชำระเงินแล้ว" on the pipeline board

- **Owner:** ag-lead (spec) → ag-dev (phase 1) → ag-ui (phase 2) → ag-qa (phase 3)
- **Date:** 2026-08-12
- **Human:** *"ผมอยากให้ Admin มีปุ่มรับชำระเงินแล้วชั่วคราว ในการทดสอบการคิดค่าคอมมิสชั่น"* →
  placement `/pipeline`, and *"มีเอาไว้ว่ายืนยันด้วย Admin"*. Option **C — ทำให้ครบ** chosen
  over A (display only) and B (ledger source column).
- **Related:** BR-3, BR-4, BR-6, ADR-017 (orders), ADR-026 (pipeline templates), TASK-054, TASK-141

---

## 1. What was found before writing this spec

Read this before writing code. Two of the three things the request implies **already exist**.

**a) A button that fires BR-4 commission is already on `/pipeline` today.** The blue
`ไป: ชำระเงินสำเร็จ` on each card calls `POST /referrals/{id}/advance`, and
`PipelineService::advance()` contains:

```php
if ($toStage === PipelineStage::CompletePayment) {
    $this->commissionService->recordForReferral($referral);
}
```

**Nothing in this task may create a second way to reach that line.** See §4.

**b) "Who confirmed" is already recorded, in two places** — `pipeline_stage_logs.changed_by_user_id`
for an advance, `orders.verified_by_user_id` for an order confirm. Neither is *displayed*, and
`verified_by_user_id` is not on `OrderResource`. That is the whole of requirement #2: a read
path, not a new column.

**c) What is genuinely missing** is the order half. `POST /orders/{order}/confirm` exists and
`OrderPolicy::confirm` already admits a Company Admin (`ownsOrManages`) — but the admin board
cannot call it, because **`ReferralResource` carries no order at all** and `Referral` has no
`orders()` relation. That is phase 1.

**d) Omise is not involved.** `PaymentMethod` has exactly two cases (`bank_transfer`,
`promptpay`). No card, no gateway call anywhere in `app/`. Nothing in this task changes that.

## 2. The distinction this task exists to fix

| | advance (exists) | order confirm (this task) |
|---|---|---|
| BR-4 ledger row | ✅ | ✅ |
| `orders.status` → `paid` | ❌ stays `pending` | ✅ |
| `paid_at`, `verified_by_user_id` | ❌ never set | ✅ |
| the customer's `/pay/{token}` page | still says unpaid, forever | closes |

Advancing a referral past Complete Payment while its order sits `pending` leaves a live public
payment page for a sale that is already booked and already paid commission on. That is the
defect, and it is reachable from the admin board today.

## 3. Phase 1 — backend (ag-dev)

**1.1 `Referral::orders()`** — `hasMany(Order::class)`. A referral may have more than one order
over its life (one cancelled, one live), so this is `hasMany`, not `hasOne`.

**1.2 One actionable order on `ReferralResource`.** Add a single key:

```php
'order' => $this->whenLoaded('orders', fn () => /* see below */),
```

The value is **the one order the board may act on**, or `null`:

- of the referral's orders, ignore `cancelled` and ignore `paid` **only if** another
  non-terminal order exists;
- prefer the newest non-terminal order (`pending` / `awaiting_verification`);
- if there is none, fall back to the newest `paid` order (so the board can show
  "ยืนยันโดย …" for a completed sale);
- otherwise `null`.

**Pick it in PHP over the loaded collection, not with a second query per row** — this endpoint
renders a whole company's board.

Shape (a deliberate subset of `OrderResource` — the board does not need the public token, and a
`public_pay_url` on an admin list is a link to a live payment page nobody asked to publish):

```
order: {
  id, order_number, status, status_label,
  amount_satang,            // BR-3 — integer satang, the UI divides by 100
  has_slip,                 // bool
  paid_at,                  // nullable ISO
  verified_by: { id, name } | null
}
```

**1.3 `verified_by` on `OrderResource` too**, same shape, `whenLoaded('verifiedBy')` — the agent's
own `OrdersView` shows the same information and must not have to guess it. Add the
`verifiedBy()` belongsTo on `Order` if it is absent.

**1.4 Eager-load.** `ReferralController::index()` must load `orders.verifiedBy` alongside its
existing relations. Follow the `ClientController::RELATIONS` precedent — **one constant, used by
every method**, not a different load list per action.

**1.5 No policy change.** `OrderPolicy::confirm` already covers Company Admin. Do not widen it.
An **Agent** must keep seeing `order: null`-or-read-only exactly as their own portal already
decides — this key is additive and must not become a new way for an agent to see another
agent's order (BR-6). Assert that.

**1.6 Tests** (`tests/Feature`):

- a referral with a `pending` order exposes it; with only a `cancelled` order exposes `null`
- a `paid` order exposes `verified_by` with the confirming admin's name
- **tenant isolation:** Company A's admin listing referrals never sees Company B's order —
  and `POST /orders/{B's order}/confirm` from A's admin is 403/404
- confirming twice is idempotent and produces **exactly one** `commission_ledger` row
- advancing to Complete Payment and *then* confirming the order marks it paid and **does not**
  write a second ledger row (`OrderService::confirmPayment`'s `alreadyClosed` branch)

## 4. Phase 2 — frontend (ag-ui), `frontend-admin/src/views/ReferralPipelineManagementView.vue`

**4.1 The rule that matters — one door, never two.**

On a card, compute:

```
canConfirmOrder = order !== null
               && order.status !== 'cancelled'
               && order.status !== 'paid'
               && (next_stage?.key === 'complete_payment' || isAtOrPastPayment(r))
```

- `canConfirmOrder` → render **`รับชำระเงินแล้ว`** and **hide the advance button entirely**.
- otherwise → the existing advance button, unchanged.

They are never rendered together. An admin looking at one card must never have to work out
which of two blue buttons books the commission.

`isAtOrPastPayment()` already exists in this file. **Do not write a second stage predicate and
do not hardcode a stage list** — a hardcoded stage list has been introduced into this codebase
three times since ADR-026 and removed three times. `PAYMENT_STAGE_KEY` from
`@/utils/pipelineStages` is the only spelling of that key.

**4.2 The action.** `POST /orders/{order.id}/confirm`, then `loadAll()`. Same error handling as
`advance()`. Route it through the existing **`ConfirmDialog`** (TASK-066) — this writes an
immutable ledger row and closes a customer's bill; it is not a toggle. Dialog text must name
the amount and say plainly that commission will be recorded:

> ยืนยันว่าได้รับเงิน {amount} บาท สำหรับ {order_number} แล้ว?
> ระบบจะบันทึกคอมมิชชั่นทันทีและแก้ไขภายหลังไม่ได้ (BR-4)

**4.3 Requirement #2 — "ยืนยันด้วย Admin".** On any card whose order is `paid`, show one muted
line: `ยืนยันโดย {verified_by.name} · {paid_at}`. If `verified_by` is `null` show
`ยืนยันโดย: ไม่ทราบ` — never blank, and never a fabricated fallback name.

**4.4 Slip.** If `has_slip` is true, say so (`มีสลิปแนบ`) so an admin knows there is something to
check before confirming. Do not build a slip viewer in this task — `GET /orders/{id}/slip`
exists and wiring it is TASK-177 if wanted.

**4.5 Stated consequence — a referral with no order gets no confirm button.** The card keeps its
advance button. Minting an order is a sales action (the agent's "เก็บเงินเลย", TASK-141) and
giving Admin a second order-creation path from a Kanban board would put two writers on
`orders.order_number` for no gain. **This is a design ruling, not a business decision** — flagged
to the human in the summary that accompanies this spec.

## 5. Phase 3 — verification (ag-qa)

- [ ] With an unpaid order: advance button **absent**, confirm button present. With no order:
      the reverse. Assert both in a component test, not by eye.
- [ ] Confirm → order `paid`, referral at Complete Payment, **exactly one** ledger row
- [ ] Confirm on a card two stages short of payment is not offered, and the endpoint 422s if
      called anyway
- [ ] Cross-tenant confirm → 403/404 (BR-6)
- [ ] `vue-tsc`, `eslint src`, `vite build`, `vitest` all clean in `frontend-admin`;
      backend `php artisan test` for the touched suites

## 6. Out of scope

- Omise / card payments / any gateway call (§1d)
- A `source` column on `commission_ledger` (option B — rejected today; if test rows and real
  rows must be told apart later, that is its own task and its own migration)
- Slip viewing, refunds, un-confirming a paid order (BR-4 forbids the last one)
- Anything in the Agent Portal beyond the additive `verified_by` in §1.3

## 7. Definition of Done

Section 9 of CLAUDE.md, plus: **no business value hardcoded**, **no second stage-order predicate
introduced**, and the §4.1 one-door rule holds.
