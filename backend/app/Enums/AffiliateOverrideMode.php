<?php

namespace App\Enums;

// TASK-194 §3.1 — products.affiliate_override_mode. Only meaningful when
// the product's effectivePlanType() is Affiliate; ignored otherwise. NULL
// on the column is treated as Additive at calculation time
// (CommissionService::recordForReferral() — see
// Product::effectiveAffiliateOverrideMode()), matching this codebase's
// "nullable override, null = safe default" convention (see
// commission_plan_type/CommissionPlanType).
enum AffiliateOverrideMode: string
{
    // Manager's payout is paid ON TOP of the agent's own commission
    // (overrideRate% x productPriceSatang) — mirrors Unilevel's existing
    // override math exactly. Agent's own ledger row is untouched.
    case Additive = 'additive';

    // Manager's payout is CARVED OUT of the agent's own commission
    // (round(overrideRate% x agentAmount) first, then agent's row =
    // agentAmount - managerPayout) — a genuine split of one pool, not an
    // addition. See CommissionService::resolveAffiliateOverride().
    case Deductive = 'deductive';
}
