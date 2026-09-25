# ADR-046 — Complete Payment is entered only by a confirmed order

- **Status:** Accepted
- **Date:** 2026-09-25
- **Decided by:** the owner (KreangYot), on a finding raised while preparing UAT-017
- **Completes:** the 2026-08-21 security audit, rulings D1 and D2
- **Number note:** ADR-045 is held for the B+C1 commission-setup UI record, which is still awaiting the owner's go-ahead.

## Context

BR-4 fires commission at Complete Payment and nowhere else. Two code paths could move a referral into that stage:

1. `OrderService::confirmPayment()`. Since the 2026-08-21 audit it needs proof on file: a slip (the order is `AwaitingVerification`) or a gateway charge (D2). It may be called only by a Company Admin or a Super Admin, never by the agent who earns from the sale (D1).
2. `POST /referrals/{id}/advance` → `PipelineService::advance()`. This checked neither rule.

Path 2 is older than the order flow. When ADR-026 made direct sale a one-step journey (Complete Registered → Complete Payment), there was no order to wait for, so advancing *was* the sale. The audit then locked path 1 and left path 2 open.

The result: an agent could press the portal's own "ไป: ชำระเงินสำเร็จ" button on their own direct-sale deal. That booked their commission with no order, no slip and nobody from the company involved. New products default to the direct-sale template (`ProductEditView`), so this covered nearly every product. `test_a_direct_sale_referral_advances_straight_from_registration_to_payment` asserted this behaviour as intended.

## Decision

**Complete Payment is entered only with the paid order that settles the referral in hand.** `OrderService::confirmPayment()` is the only caller that has one.

- The rule lives in `PipelineService::advance()`, which takes a new `?Order $settledBy` argument. Putting it in the controller would not be enough: a second endpoint wired to `advance()` later would reopen the hole without anyone removing a guard.
- "Settles" means both of these: this referral's own order, and status `Paid`. `confirmPayment()` marks the order Paid in the same transaction just before it advances.
- It applies to **everyone**: agent, team leader, Company Admin, Super Admin. The rule is about proof, not rank. An admin who has seen a slip confirms the order that carries it.
- Every other edge is unchanged: the medical journey's earlier stages, the post-sale stages after payment, and the Ongoing Next Meeting self-loop.

## Consequences

- **Agent portal (`PipelineBoard.vue`).** A row whose next stage is payment offers no button. It says what the deal is waiting for: no order yet, the customer's slip, or an admin's confirmation. The "รับชำระเงินแล้ว" button is no longer rendered for agents. OrderPolicy has refused them since the audit, so it could only ever end in a 403.
- **Admin board (`ReferralPipelineManagementView.vue`).** A card at the payment gate with no live order can no longer be pressed or dragged into payment. One predicate, `canAdvanceByHand`, now drives both the button and `draggable`. Before this, `draggable` was `!canConfirmOrder`.
- **Legacy data.** Referrals that reached payment through the old door before today keep their ledger rows (BR-4). If such a referral still has an open order, confirming it closes the bill and books nothing twice (`alreadyClosed`), as before.
- **Tests.** About 130 backend tests reached payment by pressing advance. They now close sales through `TestCase::closeSale()`, which walks the referral by hand to the stage before payment, then has a Company Admin confirm an order with a slip. Switching them over changed no commission figure in any test.

## Verification

Each mutation below was applied, the named test failed, and the change was reverted:

| Mutation | Fails |
|---|---|
| Remove the gate | 5 tests, including an agent, an admin and a Super Admin each pressing into payment |
| `settles()` ignores status | an order still awaiting verification closes the sale |
| `settles()` ignores which referral | another referral's paid order closes this one |
| `confirmPayment()` stops passing the order | 8 tests: every legitimate close breaks |
