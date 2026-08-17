<?php

namespace App\Enums;

// ADR-011/TASK-030: commission_matrix_settings.spillover_rule — how a
// new placement is chosen when an agent's direct slots are full. Fixed
// vocabulary (like BinaryCycleFrequency), not a BR-7 business value.
//
// Only Breadth is actually implemented by MatrixPlacementService right
// now (breadth-first: fill every open slot at the current level,
// left-to-right, before spilling to the next level down) — the
// overwhelmingly standard default across real Matrix MLM systems, and
// the only one ever requested. The column/enum exist so a future
// alternative (e.g. depth-first, or "balanced") doesn't need another
// migration — MatrixPlacementService::place() throws if it ever sees a
// value it doesn't know how to execute, rather than silently falling
// back to Breadth.
enum MatrixSpilloverRule: string
{
    case Breadth = 'breadth';
}
