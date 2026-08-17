<?php

namespace App\Services\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\PaymentStatus;
use App\Models\CommissionGenerationRule;
use App\Models\CommissionGenerationSetting;
use App\Models\CommissionLedger;
use App\Models\Referral;
use App\Models\User;

/**
 * ADR-011 Section 3c (TASK-031) — Generation MLM plan type: overrides
 * paid by upline GENERATION rather than flat depth or cert tier.
 *
 * ag-lead judgment call on what counts as "a generation" (ADR-011 says
 * "1st generation of breakaway legs below, 2nd generation, etc." but
 * doesn't spell out the exact walk — same documented-not-invented
 * treatment as every other placement/walk algorithm in this family):
 * walking the selling agent's manager_id chain upward, only ancestors
 * who currently hold a rank flagged is_breakaway_rank consume a
 * generation slot (1 = the first breakaway-ranked ancestor found, 2 =
 * the next one above them, etc.) — non-breakaway ancestors in between
 * are passed through silently, earning nothing under this plan type
 * (they're not the ones who "broke away"; only a breakaway leader
 * anchors a generation). This reuses the SAME agent_ranks/
 * is_breakaway_rank concept Stairstep/Breakaway needs, per ADR-011's
 * "both require a sales-volume-based rank concept" framing — but
 * Generation and Stairstep/Breakaway are independently selectable
 * CommissionPlanType values (gated by Product::effectivePlanType()
 * exactly like Unilevel/Binary/Matrix), so only one of the two ever
 * actually fires for a given sale.
 */
class GenerationCommissionService
{
    private const MAX_CHAIN_DEPTH = 100;

    public function payGenerationOverrides(Referral $referral, User $sellingAgent, int $productPriceSatang): void
    {
        $settings = CommissionGenerationSetting::where('company_id', $referral->company_id)->first();
        if (! $settings) {
            return; // config gap — same "never guess, never block the sale" stance as the rest of this Service family.
        }

        $manager = $sellingAgent->manager;
        $depth = 0;
        $generation = 0;

        while ($manager !== null && $depth < self::MAX_CHAIN_DEPTH && $generation < $settings->max_generation_depth) {
            if ($manager->currentRank?->is_breakaway_rank) {
                $generation++;

                $rate = CommissionGenerationRule::where('company_id', $referral->company_id)
                    ->where('generation_number', $generation)
                    ->where('effective_from', '<=', now())
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
                    ->orderByDesc('effective_from')
                    ->first();

                // A generation with no configured rate gets no row at
                // all — never a $0 row (BR-4 precedent) — but the
                // generation slot is still consumed (it WAS a breakaway
                // ancestor, just an unpriced one), so the walk doesn't
                // skip past max_generation_depth's intent.
                if ($rate) {
                    CommissionLedger::create([
                        'company_id' => $referral->company_id,
                        'agent_id' => $manager->id,
                        'referral_id' => $referral->id,
                        'cert_tier_id_at_time' => null,
                        'product_id' => $referral->product_id,
                        'rate_type_applied' => $rate->rate_type,
                        'rate_applied' => $rate->rate_value,
                        'amount_satang' => CommissionRateCalculator::compute($rate->rate_type, $rate->rate_value, $productPriceSatang),
                        'payment_status' => PaymentStatus::Pending,
                        'paid_at' => null,
                        'earned_via' => CommissionEarnedVia::GenerationOverride,
                        'override_source_agent_id' => $sellingAgent->id,
                    ]);
                }
            }

            $manager = $manager->manager;
            $depth++;
        }
    }
}
