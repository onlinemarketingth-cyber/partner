<?php

namespace App\Enums;

// Agent-view IA item 1.5 ("การเคลมแต้มแลกของรางวัล") — reward_redemptions
// workflow. Pending = agent submitted, awaiting Admin decision; Approved
// = accepted, not yet handed over; Rejected = declined (points not
// deducted — see RewardRedemptionService); Fulfilled = reward actually
// given to the agent. Approved and Fulfilled are kept distinct because
// physical rewards have a real-world handover step after approval.
enum RedemptionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Fulfilled = 'fulfilled';
}
