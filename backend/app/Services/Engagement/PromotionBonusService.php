<?php

namespace App\Services\Engagement;

use App\Enums\CommissionEarnedVia;
use App\Enums\PaymentStatus;
use App\Enums\PromotionPayoutTiming;
use App\Models\AgentPromotion;
use App\Models\AgentPromotionCredit;
use App\Models\AuditLog;
use App\Models\CommissionLedger;
use App\Models\Referral;
use App\Models\User;
use App\Services\Commission\CommissionRateCalculator;
use Illuminate\Support\Facades\DB;

/**
 * TASK-042 §3 (Promotion bonus payout, BR-7 confirmed 2026-07-23):
 * "currently nothing pays out agent_promotions.bonus_value at all —
 * confirmed gap." This Service is the fix. Hooked into
 * PipelineService::advance()'s existing Complete-Payment block as a
 * THIRD block, alongside (never replacing) BR-4 commission
 * (CommissionService) and bonus XP (GamificationService) — same
 * DB::transaction, same CompletePayment guard.
 *
 * Grounding facts (TASK-042 task spec, confirmed via code read, not
 * assumed): agent_promotions has no goal-count/milestone or
 * repeat-limit field — bonus_value is a flat amount, paid once per
 * qualifying referral event, unlimited events per agent per promotion
 * (a cap is a new field/rule, explicitly out of scope for this pass).
 *
 * withoutGlobalScopes() + explicit company_id filtering throughout —
 * same rationale as BinaryCommissionService/DispatchDueRenewalCommissions
 * (see those classes' own comments): TenantScope resolves off
 * auth()->user(), which is null in a console job and bypassed entirely
 * for a Super Admin actor, so this Service must never rely on it for a
 * money-affecting write (Section 5 / BR-6). company_id is always
 * derived from the referral/credit row itself, never trusted from a
 * caller.
 */
class PromotionBonusService
{
    /**
     * Evaluates every AgentPromotion in the referral's own company
     * against the referral that just reached Complete Payment (the only
     * caller — PipelineService::advance() — already guards on that
     * stage, so this method doesn't re-check it). Creates an
     * AgentPromotionCredit for every match (the audit trail, always,
     * regardless of payout_timing), and pays it immediately when the
     * matched promotion's payout_timing says so.
     */
    public function evaluateForReferral(Referral $referral, ?User $actor): void
    {
        $agent = User::withoutGlobalScopes()->find($referral->agent_id);
        if (! $agent) {
            return;
        }

        // Narrow to this company + (product-specific or "any product",
        // per agent_promotions.product_id nullable = applies to every
        // product) at the DB level; status/date-window and
        // who-it-targets are then delegated entirely to
        // AgentPromotion::isCurrentlyActive() / ::appliesToAgent() below
        // — reused, never reimplemented, per the task's own instruction.
        $candidates = AgentPromotion::withoutGlobalScopes()
            ->where('company_id', $referral->company_id)
            ->where(fn ($query) => $query->whereNull('product_id')->orWhere('product_id', $referral->product_id))
            ->get();

        foreach ($candidates as $promotion) {
            if (! $promotion->isCurrentlyActive() || ! $promotion->appliesToAgent($agent)) {
                continue;
            }

            $this->creditPromotion($promotion, $referral, $agent, $actor);
        }
    }

    /**
     * Creates the AgentPromotionCredit audit row for one matching
     * promotion, then pays it immediately if payout_timing says so.
     * Idempotent per (agent_promotion_id, referral_id) — defensive,
     * same "check before create" philosophy as
     * CommissionService::recordForReferral(), even though the pipeline's
     * sequential state machine already makes Complete Payment
     * unreachable twice for the same referral.
     */
    private function creditPromotion(AgentPromotion $promotion, Referral $referral, User $agent, ?User $actor): void
    {
        $alreadyCredited = AgentPromotionCredit::withoutGlobalScopes()
            ->where('agent_promotion_id', $promotion->id)
            ->where('referral_id', $referral->id)
            ->exists();

        if ($alreadyCredited) {
            return;
        }

        // BR-3: integer satang end to end — CommissionRateCalculator's
        // one intermediate division is rounded away immediately, same
        // guarantee every other commission/bonus calculation in this
        // codebase relies on. Reuses the EXACT same percentage-vs-fixed
        // convention as CommissionService/BinaryCommissionService/etc.
        // (basis points for percentage, satang for fixed_satang) — no
        // new calculation scheme invented here.
        $productPriceSatang = $referral->product->price_satang;
        $bonusAmountSatang = CommissionRateCalculator::compute($promotion->bonus_type, $promotion->bonus_value, $productPriceSatang);

        $credit = AgentPromotionCredit::create([
            'company_id' => $referral->company_id,
            'agent_promotion_id' => $promotion->id,
            'referral_id' => $referral->id,
            'user_id' => $agent->id,
            'bonus_amount_satang' => $bonusAmountSatang,
        ]);

        if ($promotion->payout_timing === PromotionPayoutTiming::Immediate) {
            $this->payCredit($credit, $actor);
        }
    }

    /**
     * Writes the commission_ledger entry for one still-unpaid credit.
     * Called two ways: inline from creditPromotion() above (immediate
     * timing — runs inside PipelineService::advance()'s own
     * DB::transaction; Laravel nests this via a savepoint, so it's still
     * "the same transaction" for atomicity), and from
     * PayDueAgentPromotionCredits (monthly_batch — its own separate
     * DB::transaction per credit). Same payout logic either way, per
     * the task spec.
     *
     * BR-4: the commission_ledger row is created exactly like every
     * other earned_via row — payment_status = Pending, paid_at = null.
     * "Paid" here means "this bonus has been booked into the immutable
     * ledger", not "money has actually been disbursed to the agent" —
     * that is the existing, separate Admin mark-paid flow, identical to
     * every other commission_ledger row regardless of earned_via. The
     * commission_ledger row itself is NEVER updated after this create()
     * — the only mutation in this whole flow is on the
     * AgentPromotionCredit row (commission_ledger_id + its OWN paid_at,
     * meaning "this credit has now been booked into the ledger").
     * earned_via = PromotionBonus + source_agent_promotion_id together
     * are the marker distinguishing this row from ordinary product
     * commission (BR-4: "never conflate").
     */
    public function payCredit(AgentPromotionCredit $credit, ?User $actor): CommissionLedger
    {
        return DB::transaction(function () use ($credit, $actor) {
            $promotion = AgentPromotion::withoutGlobalScopes()->find($credit->agent_promotion_id);
            $referral = Referral::withoutGlobalScopes()->find($credit->referral_id);
            $agent = User::withoutGlobalScopes()->find($credit->user_id);

            $ledger = CommissionLedger::create([
                'company_id' => $credit->company_id,
                'agent_id' => $credit->user_id,
                'referral_id' => $credit->referral_id,
                // Snapshot of the agent's cert tier AT payout time — same
                // "snapshot, never re-derived later" spirit as every
                // other commission_ledger row, even though a promotion
                // bonus isn't itself gated by cert tier (BR-2 rates are;
                // this is an additive bonus layered on top of them, per
                // AgentPromotion's own docblock). Nullable column, so a
                // never-certified agent still gets a valid ledger row.
                'cert_tier_id_at_time' => $agent?->highestPassedCertTier()?->id,
                'product_id' => $referral?->product_id,
                'rate_type_applied' => $promotion?->bonus_type,
                'rate_applied' => $promotion?->bonus_value,
                'amount_satang' => $credit->bonus_amount_satang,
                'payment_status' => PaymentStatus::Pending,
                'paid_at' => null,
                'earned_via' => CommissionEarnedVia::PromotionBonus,
                'source_agent_promotion_id' => $credit->agent_promotion_id,
            ]);

            $credit->update([
                'commission_ledger_id' => $ledger->id,
                'paid_at' => now(),
            ]);

            // Section 6: "record every action that affects money" — a
            // promotion bonus booking is exactly that. actor_user_id is
            // nullable (audit_logs migration) — null here means the
            // scheduled command (system action), never a human.
            AuditLog::create([
                'company_id' => $credit->company_id,
                'actor_user_id' => $actor?->id,
                'action' => 'agent_promotion_credit.paid',
                'auditable_type' => AgentPromotionCredit::class,
                'auditable_id' => $credit->id,
                'old_values' => null,
                'new_values' => [
                    'agent_promotion_id' => $credit->agent_promotion_id,
                    'referral_id' => $credit->referral_id,
                    'user_id' => $credit->user_id,
                    'bonus_amount_satang' => $credit->bonus_amount_satang,
                    'commission_ledger_id' => $ledger->id,
                ],
                'ip_address' => request()?->ip(),
            ]);

            return $ledger;
        });
    }
}
