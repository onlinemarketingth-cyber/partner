# TASK-177 — the same one-door rule in the Agent Portal

- **Owner:** ag-lead (spec) → ag-ui
- **Date:** 2026-08-12
- **Origin:** ag-qa's out-of-scope note on the TASK-176 review — *"the Agent Portal still has the
  §2 defect"*. Confirmed by ag-lead before writing this.
- **Related:** TASK-176 (read it first — this is its second half), BR-3, BR-4, BR-6, ADR-026

---

## 1. The defect, restated for this app

`frontend/src/design-system/components/PipelineBoard.vue:520` posts
`/referrals/{id}/advance` with no awareness of whether the referral has an unpaid order.
Advancing into Complete Payment books BR-4 commission and leaves `orders.status = pending`,
so the customer's public `/pay/{token}` page stays live for a sale that is already closed and
already paid commission on. Identical to TASK-176 §2 — this board is just the agent's copy.

**One difference from the admin board, in our favour: this board has no drag.** `draggable`
appears nowhere in the file, so there is exactly one affordance to fix, not three. Verify that
yourself before relying on it.

## 2. What is already in place — do not rebuild any of it

- **The data is already on the wire.** TASK-176 added `order` to `ReferralResource` and
  `ReferralController::RELATIONS` eager-loads it for **every** caller, including this board's
  `GET /referrals` at line 250. No backend change is needed or wanted in this task.
- **The endpoint is already permitted.** `OrderPolicy::confirm` is `ownsOrManages` — an agent
  may confirm their own order today; `OrdersView.vue:203` already does exactly that.
- **The reference implementation exists.** `frontend-admin/src/views/ReferralPipelineManagementView.vue`
  has `canConfirmOrder()`, the `v-if`/`v-else-if`/`v-else` chain, `formatBaht()`,
  `verifiedByLine()` and 20 tests. **Read it and port the shape.** ADR-003 means the two apps do
  not share a package, so this is a deliberate copy — say so in the comment, and keep the
  predicate spelled identically so the next reader can diff them.

## 3. Scope

**In:** `frontend/src/design-system/components/PipelineBoard.vue` and its spec, only.

**Out — explicitly, and each for a reason:**

- **Any backend change.** §2.
- **`useReferralOrders.ts`.** That composable pages `GET /orders` up to 10 times to build an
  order map, which the new `order` key now makes largely redundant. Deleting a paging strategy
  is a real simplification and a real risk, it touches the "เก็บเงินเลย" flow (TASK-141) rather
  than the pipeline board, and bundling it here would make this task unreviewable. **TASK-178.**
- **`ClientsView.vue` / `ReferralRow.vue`.** Same reason.
- Slip viewing, refunds, un-confirming.

## 4. Implement

**4.1** Widen `ReferralItem` (line 141) with the optional order key. The exact shape is in
TASK-176 §1.2 — read `backend/app/Http/Resources/ReferralResource.php` for the truth, not this
document. It is **absent** when orders were not loaded, so type it `order?: … | null` and treat
absent and null identically.

**4.2** Port `canConfirmOrder(r)` from the admin view. Compose it from the payment-stage helper
this file already has and `PAYMENT_STAGE_KEY` from `@/utils/pipelineStages`. **No new stage
predicate and no hardcoded stage list** — that regression has been introduced and removed three
times since ADR-026, and TASK-176 came within one review of making it four.

If this file has no at-or-past-payment helper of its own, port the admin one **as a function,
once**, and use it in both places it is needed here. Do not inline the comparison twice.

**4.3** The button at line 738 becomes a `v-if` / `v-else-if` / `v-else` chain: confirm when
`canConfirmOrder`, otherwise advance, otherwise the end-of-journey text. **They are never
rendered together.** Structural exclusion, not two conditions maintained as each other's inverse.

**4.4** Confirm goes through the Agent Portal's own confirmation UI (check what this app uses —
it is not necessarily the admin's `ConfirmDialog`) with wording that names the amount and states
the consequence, as TASK-176 §4.2:

> ยืนยันว่าได้รับเงิน {amount} บาท สำหรับ {order_number} แล้ว?
> ระบบจะบันทึกคอมมิชชั่นทันทีและแก้ไขภายหลังไม่ได้ (BR-4)

**4.5** Show `ยืนยันโดย {name} · {paid_at}` on a paid card, `ยืนยันโดย: ไม่ทราบ` when
`verified_by` is null, and `มีสลิปแนบ` when `has_slip`. Never blank, never a fabricated name.

**4.6 BR-3.** `amount_satang` is integer satang. Divide by 100 for display only; the confirm POST
carries no body, so no baht value can reach the API.

**4.7** This is a shared design-system component rendered in two places (`/pipeline` and
`ClientsView`'s pipeline mode). Whatever you change appears in both — check the second one.

## 5. Tests

In `src/design-system/components/__tests__/`. Mirror the admin spec, including:

- both directions of §4.3 (unpaid order → confirm only; no order → advance only)
- the ADR-026 discriminator: the same stage on `direct_sale_default` vs `medical_package_default`
  must produce different doors — a hardcoded stage list cannot satisfy both
- confirm POSTs to `/orders/{id}/confirm` with **no body**
- the raw satang integer never appears in the rendered card

**Prove the tests are not tautological.** Remove the guard, observe the failure, restore, and
report the observed failure count — as ag-ui did on TASK-176. A test that passes with the fix
reverted is worse than no test, because it certifies the defect.

## 6. Verify

From `/Applications/MAMP/htdocs/agent/frontend`: `npx vue-tsc --noEmit`, `npx eslint src`,
`npm run build`, `npx vitest run`. Report real output. Baseline is **vitest 88/88**; anything red
is yours until you prove otherwise by evidence, not by assertion.

## 7. Definition of Done

Section 9 of CLAUDE.md, plus: the §4.3 one-door rule holds, no second stage predicate exists in
the file, and nothing outside §3's "in" list was touched.
