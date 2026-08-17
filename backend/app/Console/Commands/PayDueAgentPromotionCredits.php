<?php

namespace App\Console\Commands;

use App\Models\AgentPromotionCredit;
use App\Services\Engagement\PromotionBonusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// TASK-042 §3 — same scheduled-job pattern as
// DispatchDueRenewalCommissions/RunDueBinaryMatchingCycles: pays out
// every agent_promotion_credits row still awaiting its commission_ledger
// entry (payout_timing = monthly_batch never pays inline — see
// PromotionBonusService::creditPromotion() — so any row with paid_at
// still null here is, by construction, a monthly_batch credit that is
// now due). Registered monthly in routes/console.php; no per-company
// cadence config exists for this feature (unlike Binary/Stairstep's
// configurable cycle_frequency) — "monthly_batch" IS the cadence,
// platform-wide, per the confirmed TASK-042 spec.
class PayDueAgentPromotionCredits extends Command
{
    protected $signature = 'commissions:pay-promotion-credits';

    protected $description = 'Pay out every promotion-bonus credit still awaiting its commission_ledger entry (TASK-042 §3, monthly_batch payout_timing)';

    public function handle(PromotionBonusService $promotionBonusService): int
    {
        $paid = 0;

        // withoutGlobalScopes() — this never responds to a client
        // request (no authenticated actor to scope against), same
        // rationale as every other scheduled command in this codebase;
        // each credit row already carries its own company_id, so no
        // separate per-company loop is needed here.
        AgentPromotionCredit::withoutGlobalScopes()
            ->whereNull('paid_at')
            ->chunkById(50, function ($credits) use ($promotionBonusService, &$paid) {
                foreach ($credits as $credit) {
                    DB::transaction(function () use ($credit, $promotionBonusService, &$paid) {
                        // Re-fetch + lock inside the transaction — guards
                        // against a concurrent run of this same command
                        // double-paying the same credit, same defensive
                        // shape as DispatchDueRenewalCommissions.
                        $locked = AgentPromotionCredit::withoutGlobalScopes()
                            ->whereKey($credit->id)
                            ->whereNull('paid_at')
                            ->lockForUpdate()
                            ->first();

                        if (! $locked) {
                            return;
                        }

                        $promotionBonusService->payCredit($locked, null);
                        $paid++;
                    });
                }
            });

        $this->info("Paid {$paid} promotion bonus credit(s).");

        return self::SUCCESS;
    }
}
