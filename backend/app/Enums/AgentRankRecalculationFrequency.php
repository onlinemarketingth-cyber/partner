<?php

namespace App\Enums;

// ADR-011/TASK-031: agent_rank_settings.recalculation_frequency — how
// often a company's agent ranks are recalculated from trailing sales
// volume. Fixed vocabulary of admin-selectable options, same treatment
// as BinaryCycleFrequency — not a BR-7 business value itself (the
// trailing_window_days number stays in agent_rank_settings' own column,
// never hardcoded here).
enum AgentRankRecalculationFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
