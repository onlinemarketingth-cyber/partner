<?php

namespace App\Enums;

// ADR-006: users.binary_leg — which side of their upline's binary tree
// this agent was placed on. Human decision (2026-07-14): spillover
// placement is chosen manually by the referrer (left/right), never
// automatic — so this column is simply set at placement time, no
// balancing algorithm needed. Only meaningful when the agent's company
// has commission_plan_type = binary; null otherwise.
enum BinaryLeg: string
{
    case Left = 'left';
    case Right = 'right';
}
