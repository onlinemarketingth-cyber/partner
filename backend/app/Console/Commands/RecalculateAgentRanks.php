<?php

namespace App\Console\Commands;

use App\Services\Commission\StairstepCommissionService;
use Illuminate\Console\Command;

// ADR-011/TASK-031 (same scheduled-job pattern as RunDueBinaryMatchingCycles)
// — recalculates users.current_rank_id from trailing sales volume for
// every company whose agent_rank_settings.recalculation_frequency cadence
// is now due. Registered daily in routes/console.php — the coarsest
// cadence (monthly) still only needs a daily check to catch every due
// company, same reasoning as the Binary/renewal commands above it.
class RecalculateAgentRanks extends Command
{
    protected $signature = 'commissions:recalculate-agent-ranks';

    protected $description = 'Recalculate current_rank_id for every agent whose company rank-recalculation cadence is now due (ADR-011/TASK-031)';

    public function handle(StairstepCommissionService $stairstepCommissionService): int
    {
        $processed = $stairstepCommissionService->recalculateRanks();

        $this->info("Recalculated rank for {$processed} agent(s).");

        return self::SUCCESS;
    }
}
