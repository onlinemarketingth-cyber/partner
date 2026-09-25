<?php

namespace App\Console\Commands;

use App\Models\Company;
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
    /*
     * --company (2026-09-25, UAT-017). The schedule never passes it. It is for
     * an operator running the job BY HAND on the shared production host: the
     * bare command re-ranks every company that happens to be due, and "rank my
     * test company now" is not a reason to move a real company's agents. With
     * --company the sweep touches that one company and says so.
     *
     * There is deliberately no flag to skip the cadence. How often ranks may
     * move is the company's own setting (BR-7), and a hand-run that ignored it
     * would be a second, quieter way of changing it.
     */
    protected $signature = 'commissions:recalculate-agent-ranks
        {--company= : Only this company, by id or slug. Every other company is left to the schedule.}';

    protected $description = 'Recalculate current_rank_id for every agent whose company rank-recalculation cadence is now due (ADR-011/TASK-031)';

    public function handle(StairstepCommissionService $stairstepCommissionService): int
    {
        $companyOption = $this->option('company');

        if ($companyOption === null) {
            $processed = $stairstepCommissionService->recalculateRanks();
            $this->info("Recalculated rank for {$processed} agent(s).");

            return self::SUCCESS;
        }

        // By id or by slug. The slug is what an operator can read off the
        // screen they just used; an id usually is not on it.
        $query = Company::withoutGlobalScopes()->with('agentRankSetting');
        $company = ctype_digit((string) $companyOption)
            ? $query->find((int) $companyOption)
            : $query->where('slug', (string) $companyOption)->first();

        if (! $company) {
            $this->error("No company with id or slug \"{$companyOption}\". Nothing was recalculated.");

            return self::FAILURE;
        }

        if (! $company->agentRankSetting) {
            $this->error("{$company->name} (id {$company->id}) has no rank settings, so it has no ranks to calculate.");
            $this->line('Save the rank settings on the commission screen first (ขั้นที่ 2 · บันไดอันดับ).');

            return self::FAILURE;
        }

        /*
         * Said BEFORE running, not inferred from a zero afterwards. A second
         * run inside the cadence changes nothing by design, and "Recalculated
         * rank for 0 agent(s)" is the sentence that gets reported as a broken
         * job.
         */
        $nextDue = $stairstepCommissionService->nextDueAt($company->agentRankSetting);

        if ($nextDue !== null) {
            $this->warn("{$company->name} (id {$company->id}) is not due yet — its ranks were last calculated at "
                .$company->agentRankSetting->last_recalculated_at?->toDateTimeString().'.');
            $this->line('Next allowed run: '.$nextDue->toDateTimeString().'. Nothing was recalculated.');

            return self::SUCCESS;
        }

        $processed = $stairstepCommissionService->recalculateRanks($company->id);

        $this->info("Recalculated rank for {$processed} agent(s) in {$company->name} (id {$company->id}). No other company was touched.");

        return self::SUCCESS;
    }
}
