# ADR-044 — A broken-away leg stops counting toward its former upline

- **Status:** Accepted
- **Date:** 2026-09-24
- **Decided by:** the owner (KreangYot)
- **Resolves** the `TODO: CONFIRM` in `StairstepCommissionService::rolledUpThroughTheManagerChain()`, listed as open in ADR-042 and ADR-043.
- **Moves two plans:** Stairstep and Generation.

## Context

Group volume rolled every descendant's trailing sales up the whole manager
chain. `payDifferentialOverride()` has always stopped PAYING past a downline
who has reached a breakaway rank — but the volume from that same leg kept
climbing, so it still decided the former upline's rank.

The consequence is the arrangement breakaway exists to end: somebody who
recruited one large organisation years ago sits at the top rung forever,
qualified by an organisation they are no longer paid from and no longer work
with.

The question had been open since group volume was built, because excluding a
leg changes who reaches which rank, and that is the owner's call (BR-7) rather
than something to infer from the payout rule.

## Decision

**A leg whose holder currently holds a rank flagged `is_breakaway_rank` no
longer contributes to the group volume of anyone above that holder.**

The cut fires on the **child**, exactly as in the payout walk: the pair severed
is (breakaway holder, their manager). The holder therefore keeps their own
sales and everything beneath them; only the manager above receives none of it.

### The circularity, and how it is broken

Rank assignment depends on group volume, which now depends on ranks. The same
way the rest of this Service already breaks it: `current_rank_id` is a periodic
snapshot, so the exclusion reads the ranks as they stand when the sweep
*begins*. One pass, deterministic, no iteration to convergence — a
newly-broken-away leg stops counting from the following run, not the same one.

## Consequences

- **Generation moves too.** `recalculateRanks()` sweeps every company holding
  `agent_rank_settings`, not every Stairstep company, and
  `GenerationCommissionService` draws its generation boundaries at whoever
  holds a breakaway rank. Fewer people reach that rung, so a Generation
  company's generations begin in different places.
- **Only group scope is affected.** A company counting personal volume has no
  roll-up to exclude from and behaves exactly as before.
- Ranks already written are not recomputed; the next scheduled run applies it.
  Ledger rows are untouched (BR-4).

## A correction worth recording

`breakawayHolders()` first shipped with `whereNull('users.deleted_at')` and a
comment justifying it. A mutation that removed the filter left every test
green — it was never exercised — and chasing that produced the real rule,
which is not about breakaway at all:

> `$managerOf` is built with SoftDeletes ON, so a deactivated agent has no
> entry in it. For an ancestor that means they can never be walked to; for a
> seller it means `$managerOf[$sellerId] ?? null` is null and the walk never
> starts. Their sales are still counted by `personalVolumesSatang()`, which
> reads `referrals.agent_id` and never asks about the user — the volume exists
> and simply stops with them.

The filter decided nothing either way and was removed rather than kept as a
condition no test can reach. The test that chased it is kept, documented as
what it actually proves.

## Still open (owner's, BR-7)

- `volume_scope` for the real companies, and the thresholds that must move with
  it. This ADR changes how group volume is computed; it does not choose group
  volume for anybody. Three inputs are still needed to draft a ladder: expected
  team size per rung, expected volume per agent per period, and the trailing
  window.
