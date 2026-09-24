# ADR-043 — On Stairstep, the seller's own rate comes from their rank

- **Status:** Accepted
- **Date:** 2026-09-24
- **Decided by:** the owner (KreangYot), choice ค ("พร้อมตาข่าย")
- **Resolves** the open question left by ADR-042. **Amends** BR-2 for one plan.
- **Related:** ADR-011 §3c (the ladder), ADR-035 (cert tier stopped setting rates)

## Context

A Stairstep chain paid the seller the flat `commission_rules` rate and paid
every manager above them the DIFFERENCE between rank rates. The two ladders
were never tied together. ADR-042 recorded the mismatch as a config warning
(choice 1ก) on the reasoning that BR-2's cert-tier dimension made a single
"seller rate" impossible — **that reasoning was wrong**: ADR-035 had already
removed cert tier from rate resolution, leaving exactly one rate per scope.

Re-derived without it, the defect is larger than a warning can cover:

```
chain: seller → ผู้นำ(12%) → ผู้จัดการ(20%), flat seller rate 5%

  seller at ขั้นเริ่มต้น(5%)     5 + (12−5) + (20−12) = 20%
  seller PROMOTED to ผู้นำ(12%)  5 +           (20−12) = 13%
```

Promoting the seller cost the chain seven points and paid the promoted agent
nothing. The ladder built to reward climbing was charging people to climb it,
and the plan's guarantee — *the company pays the highest rank in the chain
and no more* — held only at the bottom rung.

ADR-035's own reasoning points the same way: *"Higher commission for better
results is Stairstep/Breakaway's job (agent_ranks), not Unilevel's."*

## Decision

**On `stairstep_breakaway`, a seller's own commission is computed from
`users.current_rank_id`'s rate. An agent holding no rank keeps the
`commission_rules` rate.**

Implemented as `SellerRate`, resolved once in
`CommissionService::recordForReferralLocked()` and carried into the Direct
ledger row's `rate_type_applied` / `rate_applied`.

### Deliberately unchanged

- **The other five plans.** Only Stairstep measures anything in rank rates.
  Generation borrows the ladder's breakaway *flag* and prices from
  `commission_generation_rules`; Unilevel, Binary, Matrix and Affiliate never
  read `agent_ranks`. Their payouts are byte-identical.
- **`commission_rules` is still required.** The renewal schedule reads that
  rule, and `CommissionReadinessService`'s RED state is defined as "no product
  resolves to a rate". Letting a ladder satisfy it would quietly redefine red.
- **Renewals** keep using `renewal_rate_type` / `renewal_rate_value`, which
  were always a separate figure from the original sale's.
- **Split sales**: both rows carry the *referring* agent's rate, including
  when it came from their rank. A split divides one commission; reading the
  co-agent's rank would break the "two rows sum exactly" invariant, and their
  hierarchy is already out of scope (TASK-026).

### Rejected

- **ก, warn only** (what ADR-042 shipped) — leaves the 13% intact.
- **ง, measure the first differential from the rate actually paid** — fixes
  the company's total but still pays the climber nothing extra.
- **Dropping the un-ranked fallback** — every agent starts with
  `current_rank_id = NULL` and only the scheduled recalculation writes it, so
  a recruit's first sale would pay them nothing. A worse bug than the one
  being fixed, landing on the person least equipped to question it.

## Consequences

- **Per-product and per-category rates stop reaching the seller on Stairstep
  companies.** `agent_ranks` cannot price per product. Accepted: the catalogue
  is two packages of similar price. Revisit if product margins diverge.
- **BR-2 needs amending** — "rates live in `commission_rules`" is no longer
  true for this plan. Proposed wording:

  > **BR-2 (Commission rate):** … Rates live in the **`commission_rules`**
  > config table — never hardcoded. **Exception (ADR-043):** on
  > `stairstep_breakaway` a ranked agent's own rate comes from their
  > `agent_ranks` rung; `commission_rules` remains the rate for agents who
  > hold no rank, and the source of every renewal rate.

- **Live Stairstep companies see payouts change on deploy** wherever a
  seller's rank rate differs from the flat rate. Existing ledger rows are
  untouched (BR-4).
- `CommissionReadinessService`'s `stairstep_seller_rate_mismatch` was
  re-aimed: the flat rate now matters only for un-ranked agents, so the
  banner says that instead of claiming every sale is mispriced.
- A rank change now moves the seller's own pay, not just their upline's —
  which makes the recalculation cadence a money setting rather than a
  reporting one. Daily is recommended for any company on this plan.

## Still open (owner's, BR-7)

- `volume_scope` and the thresholds that must move with it (ADR-042).
- Whether a broken-away leg still counts toward its former upline's group
  volume — decided in principle (it should not), not yet built. It touches
  Generation as well, whose generation boundaries are drawn at breakaway
  ranks.
