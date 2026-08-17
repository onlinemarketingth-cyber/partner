# TASK-191 — share the payment/voucher link from 3 places (Agent Portal)

- **Owner:** ag-lead (spec) → ag-dev (backend) → ag-ui (frontend) → ag-qa
- **Date:** 2026-08-16
- **Human:** *"สร้างปุ่มทั้ง 3 จุด ลิ้นชักลูกค้า หน้ารายชื่อลูกค้า กระบวนการขายเมื่อลูกค้าชำระเงินเสร็จแล้ว
  (เพิ่มทีชื่อลูกค้า)"* — three spots, all governed by "once the customer has paid"; the client-list
  spot goes next to the client's name.
- **Related:** TASK-176 §1.2 (the decision this task deliberately reverses), TASK-189/190 (why the
  post-payment link now matters — it's where the voucher lives and nothing currently pushes it to
  the customer besides an agent manually re-sending it).

---

## 1. What exists already, and the one real gap

**Already built, reused everywhere in this task:** `ShareLinkModal.vue` (generic — takes any
`url` + `heading`, has Link/QR tabs) and `qrCode.ts`. `OrdersView.vue` and the Clients drawer's
`ReferralRow.vue` (via `useReferralOrders`) already open it with `order.public_pay_url`. No new
modal, no new QR logic — every point below reuses these.

**The one real gap, and the reversal:** `ReferralResource.php`'s nested `order` (added by TASK-176
for the confirm-payment button) deliberately excludes `public_token`/`public_pay_url` — the comment
at the time said *"putting a live payment link on one would publish a URL nobody asked to
publish."* That reasoning no longer holds: TASK-189 made the same link the one place a paid
voucher renders, and TASK-190 exists specifically because nothing currently re-surfaces that link
to a customer after the fact. **This task reverses that exclusion, deliberately, for the reason
just stated** — recorded here rather than silently changed, per CLAUDE.md §8 rule 1.

## 2. Phase 1 (ag-dev) — expose the link where the pipeline board can use it

**1.1** `ReferralResource.php`'s nested `order` array — add `public_pay_url` (reuse
`OrderResource`'s existing accessor/derivation, don't re-derive the URL a second way). Update the
docblock at the exclusion comment to say why it's no longer excluded, referencing this task.

**1.2** Same field, same reasoning, applies to **both** frontend apps' consumption of
`ReferralResource` (Agent Portal's `PipelineBoard.vue` AND `frontend-admin`'s
`ReferralPipelineManagementView.vue` share the same backend Resource, ADR-003). No behavior change
required on the Admin side for this task, but note in your report that Admin's board now also
*has* the field available, even though this task doesn't add an Admin button — a later task could
without another backend change.

**1.3** Feature test: a referral's serialized `order.public_pay_url` matches
`OrderResource`'s own value for the same order, for both a paid and an unpaid order (the field
being present doesn't depend on payment status — the button's visibility is a frontend concern,
per §3).

## 3. Phase 2 (ag-ui, Agent Portal only) — the three buttons

**3.1 Client drawer (`ReferralRow.vue` / `useReferralOrders`'s `openShareFor`).** Currently gated
`order && order.status !== 'paid'` — hides the button the moment payment completes, which is
exactly backwards now (post-payment is when there's a voucher worth sharing). **Remove the
`status !== 'paid'` restriction** — show whenever an order exists, in every status. Keep the
existing button label/behavior for the unpaid case (sharing the payment link) unchanged; only the
paid case newly becomes visible.

**3.2 Client list — collapsed card (`ClientsView.vue`, the row shown before opening the drawer).**
New button, placed next to the client's name (human's placement instruction). **Visible only when
the client has at least one order with `status === 'paid'`** (governs all three spots per the
human's framing) — sharing a not-yet-paid order from the collapsed card is out of scope here; that
case is already covered by opening the drawer (§3.1).

**Judgment call, flag it rather than guess silently:** a client can have multiple paid orders
(different products/renewals). This button shares the **most recently paid** order
(`orders.where(status=paid).sortByDesc(paid_at).first()`). If the client only ever has one, this is
moot; if a real multi-order case surfaces where "most recent" is wrong, that's a follow-up with a
human decision, not something to solve speculatively here.

**3.3 Pipeline board (`PipelineBoard.vue`).** New button in the existing action-row
`v-if/v-else-if` chain (`canConfirmOrder` → confirm / `next_stage` → advance), **shown once the
referral's order is paid** — i.e., after the existing chain has nothing left to advance to that
needs a button, or additively whenever `order?.status === 'paid'` regardless of pipeline stage
(a paid order can still have further pipeline stages like `delivery`/`follow_up` ahead of it per
ADR-026, and the share button is about the voucher, not about which stage is next — don't couple
it to "the chain has nothing else to show"). Use `order.public_pay_url` now exposed by Phase 1.

**3.4 All three** use the existing `ShareLinkModal` exactly as `OrdersView.vue` already does — same
heading pattern (`ชำระเงิน {{ order_number }}` or equivalent), same component, no new modal.

## 4. Verification

- The reversed exclusion doesn't leak anything new to the wrong tenant/agent — `ReferralResource`
  is already scoped to the requesting agent's own referrals (BR-6 unaffected; nothing about scope
  changed, only which fields are serialized).
- Share button in the drawer now visible for a paid order (regression test: was previously hidden,
  assert it now renders).
- Client-list button appears only when a paid order exists, and resolves to the most-recently-paid
  one when several exist (test with 2+ paid orders for one client).
- Pipeline board button appears once paid, independent of remaining pipeline stage (test a paid
  referral still mid-journey, e.g. before `delivery`, still shows the share button).
- `vue-tsc --noEmit`, `eslint src` in `frontend/` (Agent Portal only — no Admin frontend changes in
  this task).
- Backend: `pint`, feature test from §2.1.3, full regression pass on `Referral`/`Order`/`Sales`
  test directories (touching a shared Resource).

## 5. Definition of Done

CLAUDE.md §9, plus: the TASK-176 exclusion is reversed with its reasoning recorded (not silently
changed), the drawer's stale hide-after-paid bug is fixed, and all three buttons share the one
existing `ShareLinkModal` component rather than each building their own.
