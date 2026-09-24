# ADR-042 — Stairstep ladder invariants and the un-ranked downline

- **Status:** Accepted
- **Date:** 2026-09-24
- **Decided by:** the owner (KreangYot), choices 1ก / 2ก / 3ก
- **Supersedes nothing.** Extends ADR-011 §3c (TASK-031) and the readiness banner of 2026-09-11.

## Context

The owner asked how to fix five failures of the Stairstep/Breakaway plan
that the platform accepts, pays money under, and never mentions:

1. A leader who recruits instead of selling personally falls below their own
   downline, the differential goes negative and no ledger row is written.
2. The seller's own rate comes from `commission_rules`; the managers' rates
   come from `agent_ranks`. Nothing ties the two together, so the plan's
   central promise — *the company's total outlay equals the highest rank
   reached in the chain* — is only true while they happen to agree.
3. A downline with no rank made their manager earn their **full** rank rate
   instead of a differential.
4. A ladder priced partly in percent and partly in fixed satang has its
   mismatched pairs skipped, silently.
5. Ranks above the breakaway rung are paid only until their downline breaks
   away, after which that income never returns.

Failures 1 and 5 are business shapes, not defects — they are settings and
ladder design, and belong to the owner (BR-7). Failures 2, 3 and 4 are the
platform being silent about states it accepts.

## Decisions

### 1ก — the seller/ladder rate disagreement is REPORTED, not enforced

`commission_rules` can price per product and per category; `agent_ranks`
cannot price per product at all. The two legitimately answer different
questions, so a company may mean them to differ. What it may not do is mean
it by accident, so `CommissionReadinessService` states the consequence with
the arithmetic done:

> อัตราผู้ขาย 10% ไม่เท่ากับขั้นต่ำสุด 5% — ยอดจ่ายรวมของสายจะเป็น 17%
> แทนที่จะเท่ากับอัตราขั้นสูงสุด 12%

**Rejected — blocking the save.** "The seller rate" is not one number: a
company can price product A and product B differently while the ladder's
bottom rung is a single rate. A save guard would refuse legitimate setups.

**Rejected for now — moving the seller's rate onto the rank.** It would
make the guarantee unconditional and ADR-035's own reasoning points that
way ("higher commission for better results is Stairstep's job"), but it
costs per-product rate differentiation for every Stairstep company and
changes money already being paid. Left open; see *Open questions*.

### 2ก — an un-ranked downline is priced at the entry rank

`payDifferentialOverride()` read the child's rate as
`$childRank->rate_value ?? 0`. On a null rank that became 0, and a child
rate of 0 makes the differential the manager's full rate.

`users.current_rank_id` starts NULL on every agent ever created and only
`commissions:recalculate-agent-ranks` ever writes it, on the company's own
cadence — up to **monthly**. So the overpay fired on the first sale of every
new recruit, for up to a month, into rows BR-4 forbids correcting.

An agent with no volume clears the `volume_threshold = 0` rung anyway, so
substituting it is not a guess — it is the answer the next recalculation
will write, reached sooner. The substitute stands in for the whole
iteration, breakaway check included.

A company with **no** threshold-0 rung has no answer to borrow. No row is
written for that hop and the walk continues (`continue`, not `break`) —
inventing a rate would violate CLAUDE.md §8 guardrail 1, and stopping the
walk would strip an entire upline because of one missing rank below it.

### 3ก — rank saves may not make the ladder WORSE

Three ladder states make money wrong or unpredictable:
`mixed_rate_types`, `rate_not_increasing` (`<=`, since an equal rate yields
a zero differential and therefore no row), `duplicate_thresholds`.

The write path counts these before and after the proposed change and
refuses only when a count **rises**.

**Rejected — requiring a valid result.** A company already holding a broken
ladder could not edit its way out: every intermediate state is still
invalid, so every repairing edit would be refused. The monotone rule cannot
deadlock, because from any ladder there is always a sequence of edits that
lowers the counts, and none of them is blocked.

`no_entry_rank` and `ranks_above_breakaway` are **advisory** — reported by
the banner, never blocking. Blocking the first would make a ladder
impossible to start building; the second is a business choice.

## Consequences

- One shared `AgentRankLadderInspector` is consulted by the write path
  (Form Requests + `AgentRankService`) and the read path (the banner), so an
  admin is never refused a save for a reason the banner phrased differently.
  Same rationale as `resolveRuleForCompany()` being a hand-checked mirror.
- **Manager payouts fall** wherever a downline had no rank: from the
  manager's full rate to a true differential. This is the only money-facing
  change in ADR-042. It corrects an overpay nobody chose.
- Every new finding is AMBER. Red stays reserved for "a deal closing right
  now pays nobody" — in all five failures the seller is paid.
- Findings route to step 2, below the rate gaps (a product with no rate pays
  nobody) and above the leader-rate gap (a ladder finding can silence a
  whole chain, not one hop).
- No frontend change: the banner renders `issues[].label` verbatim.

## Open questions (owner's, BR-7)

- **Should the seller's rate come from their rank?** ADR-035 removed cert
  tier from rate resolution precisely because rate-for-performance is
  `agent_ranks`' job. That argues for it; losing per-product pricing argues
  against. Not decided.
- **`volume_scope`** — personal or group — and the thresholds that must move
  with it. Failure 1 is unfixable in code without this answer.
- **Recalculation cadence.** Monthly leaves a promoted agent on their old
  rate for up to 28 days, uncorrectably (BR-4). Daily is recommended for any
  company on this plan.
- The existing `TODO: CONFIRM` in `rolledUpThroughTheManagerChain()` —
  does a broken-away leg still count toward its former upline's GROUP
  volume? Becomes live the moment `volume_scope` is set to Group.
