<?php

namespace App\Enums;

// TASK-042 §4 (Cert-tier "and above" targeting) — BR-7 confirmed
// 2026-07-23: shared by both `announcements.target_cert_tier_mode` and
// `agent_promotions.target_cert_tier_mode`. Exact = today's only
// behavior (agent must hold the exact target_cert_tier_id row).
// AndAbove = agent's highest passed tier's cert_tiers.sort_order must
// be >= the target tier's sort_order (see User::highestPassedCertTier()
// docblock — sort_order is the established ranking: Basic < Intermediate
// < High). Default is always Exact, so existing rows keep identical
// behavior (backward-compatibility requirement, not just a convenience
// default).
enum CertTierTargetMode: string
{
    case Exact = 'exact';
    case AndAbove = 'and_above';
}
