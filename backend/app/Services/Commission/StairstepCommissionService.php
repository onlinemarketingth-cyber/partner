<?php

namespace App\Services\Commission;

use App\Enums\AgentRankRecalculationFrequency;
use App\Enums\CommissionEarnedVia;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Enums\UserRole;
use App\Models\AgentRank;
use App\Models\Company;
use App\Models\CommissionLedger;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADR-011 Section 3c (TASK-031) — Stairstep/Breakaway MLM plan type.
 * Two deliberately separate responsibilities, mirroring the Binary/
 * Matrix Services' own split:
 *
 *   - recalculateRanks() runs on a SCHEDULE (RecalculateAgentRanks) and
 *     is the only thing that ever writes users.current_rank_id.
 *   - payDifferentialOverride() runs SYNCHRONOUSLY inside
 *     CommissionService::recordForReferral() (Complete Payment) and
 *     only ever READS current_rank_id — it never recalculates rank
 *     mid-sale (ranks are a periodic snapshot, not computed live, same
 *     "config gap is a human problem, not something to guess at" stance
 *     as every other Service in this family).
 */
class StairstepCommissionService
{
    // Same circuit-breaker rationale as every other manager_id walk in
    // this Service family (Section 7 "no magic numbers" — not a real
    // business-defined depth cap).
    private const MAX_CHAIN_DEPTH = 100;

    /**
     * Sweeps every company with agent_rank_settings configured and
     * recalculates current_rank_id for every Agent whose company's
     * recalculation cadence is now due.
     *
     * @return int number of agents recalculated this run
     */
    public function recalculateRanks(): int
    {
        $processed = 0;

        Company::whereHas('agentRankSetting')
            ->with('agentRankSetting')
            ->chunkById(50, function ($companies) use (&$processed) {
                foreach ($companies as $company) {
                    $processed += $this->recalculateCompanyRanks($company);
                }
            });

        return $processed;
    }

    private function recalculateCompanyRanks(Company $company): int
    {
        $settings = $company->agentRankSetting;

        $intervalDays = match ($settings->recalculation_frequency) {
            AgentRankRecalculationFrequency::Daily => 1,
            AgentRankRecalculationFrequency::Weekly => 7,
            AgentRankRecalculationFrequency::Monthly => 30,
        };

        $due = $settings->last_recalculated_at === null
            || $settings->last_recalculated_at->lte(now()->subDays($intervalDays));

        if (! $due) {
            return 0;
        }

        // Highest volume_threshold first — the first rank an agent's
        // trailing volume clears (walking from the top down) is their
        // new rank; an agent clearing no threshold at all keeps
        // current_rank_id = null (un-ranked), same "no row = no
        // guessed default" stance as every BR-7 lookup elsewhere.
        $ranks = AgentRank::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderByDesc('volume_threshold')
            ->get();

        $processed = 0;

        // withoutGlobalScopes() — background job, same rationale as
        // BinaryCommissionService::processCompanyCycles(); explicit
        // company_id/role filtering below does the real scoping.
        User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('role', UserRole::Agent)
            ->chunkById(100, function ($agents) use ($settings, $ranks, $company, &$processed) {
                foreach ($agents as $agent) {
                    $volumeSatang = $this->trailingVolumeSatang($agent->id, $company->id, $settings->trailing_window_days);

                    $newRank = $ranks->first(fn (AgentRank $rank) => $volumeSatang >= $rank->volume_threshold);

                    if ($agent->current_rank_id !== $newRank?->id) {
                        // forceFill(), not update() — current_rank_id is
                        // deliberately NOT in User::$fillable (see
                        // User::currentRank()'s own docblock: system-owned,
                        // never user-writable). This recalculation job is
                        // the one legitimate writer and must bypass mass-
                        // assignment protection explicitly rather than
                        // silently no-op through update().
                        $agent->forceFill(['current_rank_id' => $newRank?->id])->save();
                    }

                    $processed++;
                }
            });

        $settings->update(['last_recalculated_at' => now()]);

        return $processed;
    }

    /**
     * "Trailing sales volume" = sum of product price_satang for every
     * referral this agent personally sold (referrals.agent_id, not
     * co_agent_id — same "co-agent's own hierarchy is out of scope"
     * precedent as CommissionService::recordDirectSale()'s own TODO)
     * that reached Complete Payment within the trailing window. Reads
     * pipeline_stage_logs.changed_at (the actual BR-4 trigger moment)
     * rather than referrals.current_stage, because a referral's stage
     * keeps advancing past Complete Payment (Section 4.3's own state
     * machine) — current_stage alone would silently drop any sale whose
     * pipeline has since moved on to a later meeting.
     */
    private function trailingVolumeSatang(int $agentId, int $companyId, int $trailingWindowDays): int
    {
        return (int) DB::table('pipeline_stage_logs')
            ->join('referrals', 'referrals.id', '=', 'pipeline_stage_logs.referral_id')
            ->join('products', 'products.id', '=', 'referrals.product_id')
            ->where('referrals.agent_id', $agentId)
            ->where('referrals.company_id', $companyId)
            ->where('pipeline_stage_logs.to_stage', PipelineStage::CompletePayment->value)
            ->where('pipeline_stage_logs.changed_at', '>=', now()->subDays($trailingWindowDays))
            ->sum('products.price_satang');
    }

    /**
     * ag-lead judgment call on the exact walk algorithm (the human
     * approved the OVERALL rank-differential mechanism via the
     * ADR-011/TASK-031 design question, not every algorithmic detail —
     * same "mechanism confirmed, algorithm documented not asked" split
     * as MatrixCommissionService's own BFS placement docblock):
     *
     *   - Walks the selling agent's manager_id chain one hop at a time.
     *     At each hop, the ancestor (manager) earns the DIFFERENCE
     *     between their OWN rank's rate and the rate of the node
     *     directly below them in the walk (the "child") — never the
     *     cumulative difference back to the original seller. This is
     *     the standard differential/compression mechanic real Stairstep
     *     plans use so the same rate-points are never paid twice across
     *     the chain.
     *   - BEFORE paying a hop, if the CHILD has already reached a
     *     breakaway rank, the walk stops entirely (breaks) — a
     *     breakaway leg is "commission-independent of its former
     *     upline" (ADR-011), meaning nobody above the breakaway point
     *     earns anything further from that leg's sales, not even the
     *     immediate manager. This is the textbook definition of a
     *     breakaway plan (the leg becomes its own separate business).
     *   - A child with no current_rank yet (never recalculated / below
     *     the lowest rank) is treated as rate 0 — the manager earns
     *     their own full rank rate as the differential, same as if the
     *     child were the absolute floor of the ladder.
     *   - A manager whose own current_rank has a DIFFERENT rate_type
     *     than the child's rank makes the differential mathematically
     *     meaningless (can't subtract a percentage from a fixed-satang
     *     value) — that one hop is simply skipped (no ledger row), the
     *     walk continues past them, same "unconfigured = no row, not a
     *     guess" precedent as every other override mechanism here.
     *   - A manager with no current_rank at all is skipped the same way
     *     (nothing to compare, no row), walk continues.
     */
    public function payDifferentialOverride(Referral $referral, User $sellingAgent, int $productPriceSatang): void
    {
        $child = $sellingAgent;
        $manager = $sellingAgent->manager;
        $depth = 0;

        while ($manager !== null && $depth < self::MAX_CHAIN_DEPTH) {
            $childRank = $child->currentRank;

            if ($childRank?->is_breakaway_rank) {
                break;
            }

            $managerRank = $manager->currentRank;

            if ($managerRank) {
                $childRateTypeMismatch = $childRank && $childRank->rate_type !== $managerRank->rate_type;

                if (! $childRateTypeMismatch) {
                    $childRateValue = $childRank->rate_value ?? 0;
                    $differential = $managerRank->rate_value - $childRateValue;

                    if ($differential > 0) {
                        $amountSatang = CommissionRateCalculator::compute($managerRank->rate_type, $differential, $productPriceSatang);

                        // Never a $0 ledger row — same BR-4 precedent as
                        // every other override mechanism in this family.
                        if ($amountSatang > 0) {
                            CommissionLedger::create([
                                'company_id' => $referral->company_id,
                                'agent_id' => $manager->id,
                                'referral_id' => $referral->id,
                                'cert_tier_id_at_time' => null,
                                'product_id' => $referral->product_id,
                                'rate_type_applied' => $managerRank->rate_type,
                                'rate_applied' => $differential,
                                'amount_satang' => $amountSatang,
                                'payment_status' => PaymentStatus::Pending,
                                'paid_at' => null,
                                'earned_via' => CommissionEarnedVia::StairstepOverride,
                                'override_source_agent_id' => $sellingAgent->id,
                            ]);
                        }
                    }
                }
            }

            $child = $manager;
            $manager = $manager->manager;
            $depth++;
        }
    }
}
