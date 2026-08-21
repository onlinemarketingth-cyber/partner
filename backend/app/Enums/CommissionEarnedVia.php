<?php

namespace App\Enums;

// BR-4: commission_ledger.earned_via — distinguishes why a ledger row
// exists so reports/managers can separate "my own sales" from
// "my team's overrides" etc. (TASK-024/025/026, ADR-006).
// - Direct: agent's own sale, Complete Payment trigger (today's only case).
// - Renewal: TASK-024, a later renewal-year payout on the same referral.
// - Override: TASK-025, a manager's cut of a downline's direct sale.
// - BinaryMatch: ADR-006 Round 4 / TASK-029, a matched-volume cycle
//   payout under a Binary plan — NOT tied to one referral/product/cert
//   tier, tied to a binary_matching_cycles row instead.
// - MatrixOverride: ADR-011/TASK-030, an ancestor's cut of a downline's
//   direct sale under a Matrix plan — keyed by LEVEL (hops up
//   matrix_placements), not by cert tier like Override/Unilevel above.
// - StairstepOverride: ADR-011/TASK-031, an upline's RANK-DIFFERENTIAL
//   cut of a downline's direct sale under a Stairstep/Breakaway plan —
//   stops once that downline leg reaches a breakaway rank (see
//   StairstepCommissionService::payDifferentialOverride()).
// - GenerationOverride: ADR-011/TASK-031, a former-upline's cut of a
//   now-broken-away leg's direct sale under a Generation plan — keyed
//   by GENERATION number (breakaway legs counted, not raw manager_id
//   hops), paid instead of (never alongside) StairstepOverride once a
//   leg has broken away.
// - PromotionBonus: TASK-042 §3, a flat/percentage bonus from an
//   agent_promotions campaign, paid when a targeted referral reaches
//   Complete Payment (same trigger as the Direct row on the same
//   referral_id — see PromotionBonusService). Distinguishes this row
//   from ordinary product commission (BR-4: "never conflate"), paired
//   with the nullable source_agent_promotion_id column (same marker
//   pattern as source_binary_cycle_id for BinaryMatch above) and an
//   agent_promotion_credits row (the audit/traceability record —
//   commission_ledger itself stays the single, undecorated payout
//   ledger every other earned_via already uses).
enum CommissionEarnedVia: string
{
    case Direct = 'direct';
    case Renewal = 'renewal';
    case Override = 'override';
    case BinaryMatch = 'binary_match';
    case MatrixOverride = 'matrix_override';
    case StairstepOverride = 'stairstep_override';
    case GenerationOverride = 'generation_override';
    case PromotionBonus = 'promotion_bonus';

    /*
     * SECURITY AUDIT 2026-08-21 (V15, human ruling D3) — the one case that
     * is not an earning.
     *
     * A reversal row carries a NEGATIVE amount_satang and points at the row
     * it reverses (reverses_commission_ledger_id). It is a case here rather
     * than a boolean column because everything downstream already switches
     * on earned_via, and because "how was this earned: it wasn't" is
     * genuinely one of the answers this vocabulary has to be able to give.
     *
     * The original row is never touched. "This sale was paid and later
     * refunded" and "this sale never happened" are different facts, and the
     * ledger has to be able to state the first one.
     */
    case Reversal = 'reversal';

    /** True for a row that takes money back rather than paying it out. */
    public function isReversal(): bool
    {
        return $this === self::Reversal;
    }
}
