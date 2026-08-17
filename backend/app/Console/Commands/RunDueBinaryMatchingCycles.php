<?php

namespace App\Console\Commands;

use App\Services\Commission\BinaryCommissionService;
use Illuminate\Console\Command;

// ADR-011/TASK-029 (ADR-006 Round 4 schema, ADR-004 scheduled-job
// pattern reused, same as DispatchDueRenewalCommissions) — turns
// accumulated binary_leg_volumes balances into commission_ledger rows
// for every company/agent whose matching cycle is now due, per that
// company's commission_binary_settings.cycle_frequency. Registered
// daily in routes/console.php — coarser cadences (weekly/biweekly/
// monthly) still only need a daily check, same reasoning as
// DispatchDueRenewalCommissions' own daily schedule for calendar-day
// due dates.
class RunDueBinaryMatchingCycles extends Command
{
    protected $signature = 'commissions:run-binary-cycles';

    protected $description = 'Process every due Binary matched-volume cycle and record the resulting commission_ledger entries (ADR-011/TASK-029)';

    public function handle(BinaryCommissionService $binaryCommissionService): int
    {
        $processed = $binaryCommissionService->runDueCycles();

        $this->info("Processed {$processed} binary matching cycle(s).");

        return self::SUCCESS;
    }
}
