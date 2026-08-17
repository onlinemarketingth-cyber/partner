<?php

namespace App\Enums;

// TASK-042 §3 (Promotion bonus payout, BR-7 confirmed 2026-07-23):
// admin-configurable per promotion, set once at creation (no default —
// see the migration adding this column: every promotion must make this
// choice explicitly, there is no sensible platform-wide fallback).
// - Immediate: PromotionBonusService pays the bonus (writes the
//   commission_ledger entry) the instant the qualifying referral hits
//   Complete Payment, inside the same DB transaction as BR-4 commission
//   + bonus XP (PipelineService::advance()).
// - MonthlyBatch: the qualifying event is still recorded immediately
//   (an agent_promotion_credits row, for audit/traceability), but the
//   actual commission_ledger write is deferred to a scheduled command
//   (PayDueAgentPromotionCredits) that runs monthly — same pattern as
//   the existing Binary/Stairstep/Renewal scheduled commands.
enum PromotionPayoutTiming: string
{
    case Immediate = 'immediate';
    case MonthlyBatch = 'monthly_batch';
}
