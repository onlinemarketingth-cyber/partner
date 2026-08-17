<?php

namespace App\Enums;

// TASK-042 §2 (Physical reward fulfillment, confirmed 2026-07-23):
// reward_items.reward_type. Physical is the priority fulfillment path
// built now (shipping capture on reward_redemptions); Digital is only
// the flag itself — auto-delivery (e-coupon generation/emailing) is
// explicitly out of scope for this pass (see TASK-042 "Out of scope").
enum RewardType: string
{
    case Physical = 'physical';
    case Digital = 'digital';
}
