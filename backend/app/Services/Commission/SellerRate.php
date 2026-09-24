<?php

namespace App\Services\Commission;

use App\Enums\CommissionRateType;
use App\Models\AgentRank;
use App\Models\CommissionRule;

/**
 * THE RATE A SELLER IS PAID ON THEIR OWN SALE, AND WHERE IT CAME FROM.
 *
 * ═══ WHY THIS IS NOT JUST A CommissionRule ═══
 *
 * Owner decision 2026-09-24 (choice ค, ADR-043): on Stairstep the seller's
 * own rate is their RANK's rate, not the flat rate in commission_rules.
 *
 * The number that forced it. Chain seller → ผู้นำ(12%) → ผู้จัดการ(20%),
 * flat rate 5%:
 *
 *   seller at ขั้นเริ่มต้น(5%)  →  5 + (12-5) + (20-12) = 20%   ✓
 *   seller PROMOTED to ผู้นำ(12%) →  5 + (20-12)        = 13%   ✗
 *
 * Promoting the seller made the company pay SEVEN POINTS LESS and handed
 * the promoted agent nothing — the ladder built to reward climbing was
 * quietly punishing it, and every rung of that ladder made the plan's own
 * guarantee (total = the highest rank in the chain) less true. Reading the
 * seller's rate off the same ladder the differentials are measured against
 * is what makes the arithmetic close.
 *
 * ═══ THE FALLBACK IS NOT A DETAIL ═══
 *
 * `users.current_rank_id` starts NULL on every agent and only the scheduled
 * recalculation ever writes it. Without a fallback, choice ค would mean a
 * new recruit earns NOTHING on their own first sale — a worse failure than
 * the one being fixed, and one that lands on the person least able to
 * understand it. So an un-ranked seller keeps being paid the
 * commission_rules rate, exactly as before.
 *
 * That is also why this object carries its SOURCE. "5%" tells a reader
 * nothing about which table has to change to move it, and on this plan the
 * answer differs per agent.
 */
final readonly class SellerRate
{
    private function __construct(
        public CommissionRateType $rateType,
        public int $rateValue,
        /** 'agent_rank' | 'commission_rule' */
        public string $source,
    ) {}

    /** The flat rate — every plan but Stairstep, and Stairstep's un-ranked sellers. */
    public static function fromRule(CommissionRule $rule): self
    {
        return new self($rule->rate_type, (int) $rule->rate_value, 'commission_rule');
    }

    /** The seller's own rung, on Stairstep. */
    public static function fromRank(AgentRank $rank): self
    {
        return new self($rank->rate_type, (int) $rank->rate_value, 'agent_rank');
    }

    public function cameFromRank(): bool
    {
        return $this->source === 'agent_rank';
    }
}
