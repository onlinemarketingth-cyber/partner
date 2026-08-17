<?php

namespace App\Services\Commission;

use App\Enums\BinaryCycleFrequency;
use App\Enums\BinaryLeg;
use App\Enums\CommissionEarnedVia;
use App\Enums\PaymentStatus;
use App\Models\BinaryLegVolume;
use App\Models\BinaryMatchingCycle;
use App\Models\Company;
use App\Models\CommissionBinarySetting;
use App\Models\CommissionLedger;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADR-011/TASK-029 — builds the matched-volume-per-cycle calculation
 * engine on top of the schema ADR-006 Round 4 already built (Binary was
 * "under development" — no CommissionService logic ever read/wrote it
 * — until now). Two deliberately separate responsibilities:
 *
 *   - creditVolume() runs SYNCHRONOUSLY inside the same request as
 *     CommissionService::recordForReferral() (Complete Payment) and
 *     only ever touches the RUNNING BALANCE in binary_leg_volumes.
 *   - runDueCycles() runs on a schedule (RunDueBinaryMatchingCycles)
 *     and is the ONLY thing that ever turns accumulated balance into an
 *     actual commission_ledger row.
 *
 * This split mirrors the real Binary mechanic ADR-006 Round 4
 * explicitly chose over the simplified per-leg-% shortcut: matching
 * happens in periodic cycles, not per-sale.
 */
class BinaryCommissionService
{
    // Same circuit-breaker rationale as CommissionService's own
    // MAX_OVERRIDE_CHAIN_DEPTH (Section 7 "no magic numbers") — guards
    // against a corrupted/cyclic manager_id chain, not a real
    // business-defined depth cap.
    private const MAX_CHAIN_DEPTH = 100;

    /**
     * ag-lead judgment call — ADR-006 built the schema but never
     * specified HOW volume rolls up the tree (same "documented, not
     * silently assumed" pattern as CommissionService::recordOverrides'
     * own judgment-call docblock, which this deliberately mirrors):
     * volume rolls up EVERY ancestor in the manager_id chain, no depth
     * cap — reusing the exact uncapped-chain-walk precedent ADR-006
     * Round 2 already decided for Unilevel overrides on this identical
     * column, and structurally the standard real-world Binary behavior
     * (every upline sees a downline sale credited on the correct leg,
     * not just the immediate parent — a "credit only the direct
     * manager" design wouldn't actually be a binary TREE mechanic at
     * all). At each hop, the credited leg is whichever side the CHILD
     * (the node one level down) was placed on ($child->binary_leg)
     * relative to that ancestor — a node with no binary_leg set (never
     * placed) simply contributes no volume at that one hop, but the
     * walk continues past them regardless.
     */
    public function creditVolume(Referral $referral, User $sellingAgent, int $volumeSatang): void
    {
        $child = $sellingAgent;
        $manager = $sellingAgent->manager;
        $depth = 0;

        while ($manager !== null && $depth < self::MAX_CHAIN_DEPTH) {
            if ($child->binary_leg !== null) {
                $column = $child->binary_leg === BinaryLeg::Left ? 'left_volume_satang' : 'right_volume_satang';

                $legVolume = BinaryLegVolume::firstOrCreate(
                    ['company_id' => $referral->company_id, 'agent_id' => $manager->id],
                    ['left_volume_satang' => 0, 'right_volume_satang' => 0],
                );
                $legVolume->increment($column, $volumeSatang);
            }

            $child = $manager;
            $manager = $manager->manager;
            $depth++;
        }
    }

    /**
     * Sweeps every agent with accumulated (>0) leg volume belonging to
     * a company that has commission_binary_settings configured, and
     * processes any cycle now due per that company's cycle_frequency.
     * Gated on "has commission_binary_settings configured" rather than
     * "company's DEFAULT plan type = binary" — ADR-011/TASK-027 lets an
     * individual PRODUCT override to Binary even when the company
     * default is something else, but the rate/cadence/cap config is
     * still company-wide (no per-product Binary settings table), so a
     * company only needs ONE settings row regardless of whether Binary
     * is its default or just one product's override.
     *
     * @return int number of agents whose cycle was processed this run (matched volume > 0 or not — every DUE agent counts once)
     */
    public function runDueCycles(): int
    {
        $processed = 0;

        Company::whereHas('commissionBinarySetting')
            ->with('commissionBinarySetting')
            ->chunkById(50, function ($companies) use (&$processed) {
                foreach ($companies as $company) {
                    $processed += $this->processCompanyCycles($company);
                }
            });

        return $processed;
    }

    private function processCompanyCycles(Company $company): int
    {
        $settings = $company->commissionBinarySetting;
        $intervalDays = match ($settings->cycle_frequency) {
            BinaryCycleFrequency::Weekly => 7,
            BinaryCycleFrequency::Biweekly => 14,
            BinaryCycleFrequency::Monthly => 30,
        };

        $processed = 0;

        // withoutGlobalScopes() — same background-job rationale as
        // DispatchDueRenewalCommissions: this never responds to a
        // client request, so TenantScope's cross-tenant-leak concern
        // doesn't apply; explicit company_id filtering below does the
        // real scoping instead.
        BinaryLegVolume::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where(function ($query) {
                $query->where('left_volume_satang', '>', 0)->orWhere('right_volume_satang', '>', 0);
            })
            ->chunkById(50, function ($legVolumes) use ($settings, $intervalDays, &$processed) {
                foreach ($legVolumes as $legVolume) {
                    $due = $legVolume->last_cycle_at === null
                        || $legVolume->last_cycle_at->lte(now()->subDays($intervalDays));

                    if (! $due) {
                        continue;
                    }

                    $this->processAgentCycle($legVolume->id, $settings);
                    $processed++;
                }
            });

        return $processed;
    }

    /**
     * BR-4: commission_ledger rows are immutable once created — the
     * mutual FK between binary_matching_cycles.commission_ledger_id and
     * commission_ledger.source_binary_cycle_id is therefore resolved by
     * creating the CYCLE row first (commission_ledger_id starts null),
     * then the LEDGER row (source_binary_cycle_id is already known —
     * the cycle's id), then updating the CYCLE (never the ledger) to
     * point at the ledger it produced. binary_matching_cycles carries
     * no stated immutability rule (it is the audit snapshot BR-4 talks
     * about linking TO, not the ledger entry itself), so this update is
     * safe.
     */
    private function processAgentCycle(int $legVolumeId, CommissionBinarySetting $settings): void
    {
        DB::transaction(function () use ($legVolumeId, $settings) {
            // Re-fetch + lock inside the transaction — same "claim
            // commits before the side effect" pattern as
            // DispatchDueRenewalCommissions, guarding against a
            // concurrent run double-processing this agent.
            $locked = BinaryLegVolume::withoutGlobalScopes()->whereKey($legVolumeId)->lockForUpdate()->first();
            if (! $locked) {
                return;
            }

            $left = $locked->left_volume_satang;
            $right = $locked->right_volume_satang;
            $matched = min($left, $right);
            $periodStart = ($locked->last_cycle_at ?? $locked->created_at)->toDateString();
            $periodEnd = now()->toDateString();

            $cycle = BinaryMatchingCycle::create([
                'company_id' => $locked->company_id,
                'agent_id' => $locked->agent_id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'left_volume_satang' => $left,
                'right_volume_satang' => $right,
                'matched_volume_satang' => $matched,
                'unmatched_carried_satang' => $settings->carry_over_unmatched ? (max($left, $right) - $matched) : 0,
                'commission_ledger_id' => null,
            ]);

            if ($matched > 0) {
                $amountSatang = CommissionRateCalculator::compute($settings->matched_rate_type, $settings->matched_rate_value, $matched);

                if ($settings->payout_cap_satang !== null) {
                    $amountSatang = min($amountSatang, $settings->payout_cap_satang);
                }

                // Never a $0 ledger row — same BR-4/TASK-025 precedent
                // already established elsewhere in this Service family
                // (e.g. a manager with no configured override rate gets
                // no row at all, not a $0 one).
                if ($amountSatang > 0) {
                    $ledger = CommissionLedger::create([
                        'company_id' => $locked->company_id,
                        'agent_id' => $locked->agent_id,
                        'referral_id' => null,
                        'cert_tier_id_at_time' => null,
                        'product_id' => null,
                        'rate_type_applied' => $settings->matched_rate_type,
                        'rate_applied' => $settings->matched_rate_value,
                        'amount_satang' => $amountSatang,
                        'payment_status' => PaymentStatus::Pending,
                        'paid_at' => null,
                        'earned_via' => CommissionEarnedVia::BinaryMatch,
                        'source_binary_cycle_id' => $cycle->id,
                    ]);

                    $cycle->update(['commission_ledger_id' => $ledger->id]);
                }
            }

            $locked->update([
                'left_volume_satang' => $settings->carry_over_unmatched ? $left - $matched : 0,
                'right_volume_satang' => $settings->carry_over_unmatched ? $right - $matched : 0,
                'last_cycle_at' => now(),
            ]);
        });
    }
}
