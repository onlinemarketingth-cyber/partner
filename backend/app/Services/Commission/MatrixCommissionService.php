<?php

namespace App\Services\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\MatrixSpilloverRule;
use App\Enums\PaymentStatus;
use App\Models\CommissionLedger;
use App\Models\CommissionMatrixLevelRate;
use App\Models\CommissionMatrixSetting;
use App\Models\MatrixPlacement;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-011 Section 3b (TASK-030) — Matrix MLM plan type: forced
 * width x depth placement with spillover, and per-level commission
 * payout. Two responsibilities, both synchronous (unlike Binary — a
 * Matrix sale pays immediately up to `depth` levels, there is no
 * matched-volume cycle mechanic here):
 *
 *   - place() runs whenever an agent under a Matrix-plan company gets a
 *     sponsor assigned (hooked into UserService::update() — see that
 *     class's own comment) and assigns them a permanent slot in the
 *     company's matrix tree.
 *   - payDownlineOverrides() runs inside CommissionService::
 *     recordForReferral(), same call site as the Unilevel/Binary
 *     equivalents, gated the same way (see that Service's own comment
 *     on why exactly one of the three ever fires per sale).
 */
class MatrixCommissionService
{
    /**
     * ag-lead judgment call (ADR-011 specced width/depth/spillover_rule
     * as config but not the exact placement algorithm): breadth-first
     * spillover — search the sponsor's own subtree level by level,
     * left to right, for the first slot with fewer than `width`
     * children, so a new recruit lands as close to their actual sponsor
     * as the tree's current fill allows. This is the standard/default
     * Matrix placement algorithm (see MatrixSpilloverRule's own
     * docblock) and is the only one this method knows how to execute —
     * see the exception below if a company is ever configured with a
     * different spillover_rule value.
     *
     * Idempotent: an agent already placed keeps their existing slot —
     * placement is permanent once assigned, same "never silently moved"
     * assumption real Matrix systems make (spillover only ever affects
     * where a NEW recruit lands, never an existing one).
     */
    public function place(User $newAgent, User $sponsor): MatrixPlacement
    {
        $existing = MatrixPlacement::where('user_id', $newAgent->id)->first();
        if ($existing) {
            return $existing;
        }

        $companyId = $newAgent->company_id;

        $settings = CommissionMatrixSetting::where('company_id', $companyId)->first();
        if (! $settings) {
            throw ValidationException::withMessages([
                'manager_id' => 'This company has no Matrix settings configured yet (width/depth/spillover_rule) — configure commission-matrix-settings before placing agents.',
            ]);
        }

        if ($settings->spillover_rule !== MatrixSpilloverRule::Breadth) {
            // Guardrail 3 — never silently proceed on a mechanic this
            // code doesn't actually implement.
            throw new \RuntimeException("MatrixCommissionService::place() does not know how to execute spillover_rule={$settings->spillover_rule->value}");
        }

        $sponsorPlacement = MatrixPlacement::where('user_id', $sponsor->id)->first();

        if (! $sponsorPlacement) {
            // Bootstrap case: the sponsor themselves isn't in the tree
            // yet. Only valid if the company's matrix has no root at
            // all — the sponsor then BECOMES the root, and the new
            // agent is placed under them next (below).
            $rootExists = MatrixPlacement::where('company_id', $companyId)->whereNull('parent_id')->exists();

            if ($rootExists) {
                throw ValidationException::withMessages([
                    'manager_id' => 'The selected sponsor is not yet placed in this company\'s Matrix tree.',
                ]);
            }

            $sponsorPlacement = MatrixPlacement::create([
                'company_id' => $companyId,
                'user_id' => $sponsor->id,
                'parent_id' => null,
                'position' => 0,
            ]);
        }

        $parentId = $this->findOpenSlotBreadthFirst($sponsor->id, $companyId, $settings->width);
        $position = MatrixPlacement::where('company_id', $companyId)->where('parent_id', $parentId)->count();

        return MatrixPlacement::create([
            'company_id' => $companyId,
            'user_id' => $newAgent->id,
            'parent_id' => $parentId,
            'position' => $position,
        ]);
    }

    /**
     * Breadth-first search starting at $rootUserId for the first node
     * (inclusive of $rootUserId itself) with fewer than $width children
     * already placed. No depth limit on the SEARCH itself (unlike the
     * commission PAYOUT depth, which is a separate, deliberate cap —
     * see payDownlineOverrides()) — physical tree growth is unbounded,
     * only how many levels actually EARN commission is capped. This
     * mirrors the Unilevel/Binary precedent of "the chain can be any
     * length, what's capped is how far payout reaches."
     */
    private function findOpenSlotBreadthFirst(int $rootUserId, int $companyId, int $width): int
    {
        $queue = [$rootUserId];

        while ($queue) {
            $current = array_shift($queue);

            $children = MatrixPlacement::where('company_id', $companyId)
                ->where('parent_id', $current)
                ->orderBy('position')
                ->pluck('user_id')
                ->all();

            if (count($children) < $width) {
                return $current;
            }

            array_push($queue, ...$children);
        }

        // Unreachable in practice (the search always finds an opening
        // once it walks far enough — the tree is unbounded), but PHP
        // needs a return; treat an empty queue as "place at the root's
        // own next slot" defensively rather than ever throwing mid-sale.
        return $rootUserId;
    }

    /**
     * Walks matrix_placements.parent_id upward from the selling agent,
     * paying commission_matrix_level_rates' configured rate at each
     * level (1 = immediate parent) up to commission_matrix_settings.depth
     * — a hard stop, unlike Unilevel's uncapped override chain, because
     * Matrix is inherently a depth-capped structure (ADR-006's taxonomy
     * research: "width AND depth are both capped"). A level with no
     * configured rate gets no row at all — never a $0 row, same BR-4
     * precedent as every other override mechanism in this Service
     * family.
     */
    public function payDownlineOverrides(Referral $referral, User $sellingAgent, int $productPriceSatang): void
    {
        $settings = CommissionMatrixSetting::where('company_id', $referral->company_id)->first();
        if (! $settings) {
            return; // config gap — same "never guess, never block the sale" philosophy as CommissionService::recordForReferral().
        }

        $placement = MatrixPlacement::where('user_id', $sellingAgent->id)->first();
        if (! $placement) {
            return; // seller was never placed in the tree — nothing to walk.
        }

        $parentId = $placement->parent_id;
        $level = 1;

        while ($parentId !== null && $level <= $settings->depth) {
            $rate = CommissionMatrixLevelRate::where('company_id', $referral->company_id)
                ->where('level', $level)
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
                ->orderByDesc('effective_from')
                ->first();

            if ($rate) {
                CommissionLedger::create([
                    'company_id' => $referral->company_id,
                    'agent_id' => $parentId,
                    'referral_id' => $referral->id,
                    'cert_tier_id_at_time' => null,
                    'product_id' => $referral->product_id,
                    'rate_type_applied' => $rate->rate_type,
                    'rate_applied' => $rate->rate_value,
                    'amount_satang' => CommissionRateCalculator::compute($rate->rate_type, $rate->rate_value, $productPriceSatang),
                    'payment_status' => PaymentStatus::Pending,
                    'paid_at' => null,
                    'earned_via' => CommissionEarnedVia::MatrixOverride,
                    'override_source_agent_id' => $sellingAgent->id,
                ]);
            }

            $parentPlacement = MatrixPlacement::where('user_id', $parentId)->first();
            $parentId = $parentPlacement?->parent_id;
            $level++;
        }
    }
}
