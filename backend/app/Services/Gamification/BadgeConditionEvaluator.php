<?php

namespace App\Services\Gamification;

use App\Models\ModuleCompletion;
use App\Models\Referral;
use App\Models\User;
use App\Models\XpLedger;
use App\Services\Referral\ClosedDealPredicate;

/**
 * Phase 10 — ERD-001 open question #9: a BASIC condition-evaluation
 * engine for Badge.condition_config. Deliberately supports only 3
 * whitelisted metrics with 5 whitelisted comparison operators — this is
 * NOT a general expression language (no AND/OR nesting beyond an
 * implicit AND across the array, no arbitrary formulas) precisely so it
 * can never be tricked into evaluating something unexpected. Real
 * per-badge thresholds are still entirely Admin-authored config
 * (condition_config itself), never a hardcoded number here (BR-7).
 *
 * condition_config shape (JSON array, ALL entries must pass — AND):
 *   [{"metric": "xp_total", "operator": ">=", "value": 500}, ...]
 *
 * Unknown/malformed metric or operator => that condition evaluates to
 * false (fail closed — never award a badge from a config this Service
 * doesn't fully understand).
 */
class BadgeConditionEvaluator
{
    public const SUPPORTED_METRICS = ['xp_total', 'modules_completed_count', 'referrals_completed_count'];

    public const SUPPORTED_OPERATORS = ['>=', '>', '==', '<=', '<'];

    /**
     * @param  array<int, array{metric?: mixed, operator?: mixed, value?: mixed}>  $conditions
     */
    public function evaluate(array $conditions, User $user): bool
    {
        // No conditions configured at all => never auto-award (stays a
        // manual-only badge, same as before Phase 10 for every
        // condition_config === null badge).
        if (empty($conditions)) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (! $this->conditionPasses($condition, $user)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{metric?: mixed, operator?: mixed, value?: mixed}  $condition
     */
    private function conditionPasses(array $condition, User $user): bool
    {
        $metric = $condition['metric'] ?? null;
        $operator = $condition['operator'] ?? null;
        $value = $condition['value'] ?? null;

        if (! in_array($metric, self::SUPPORTED_METRICS, true)) {
            return false;
        }
        if (! in_array($operator, self::SUPPORTED_OPERATORS, true)) {
            return false;
        }
        if (! is_int($value) && ! is_float($value)) {
            return false;
        }

        $actual = $this->metricValue($user, $metric);

        return match ($operator) {
            '>=' => $actual >= $value,
            '>' => $actual > $value,
            '==' => $actual == $value,
            '<=' => $actual <= $value,
            '<' => $actual < $value,
            default => false,
        };
    }

    private function metricValue(User $user, string $metric): int
    {
        return match ($metric) {
            // BR-5 total XP — same source as LeaderboardController/LevelService.
            'xp_total' => (int) XpLedger::where('user_id', $user->id)->sum('xp_awarded'),
            // Academy — count of distinct modules this agent has completed.
            'modules_completed_count' => ModuleCompletion::where('user_id', $user->id)->count(),
            // "closing a sale" (BR-5 source (b)) — referrals this agent
            // has personally brought to Complete Payment.
            'referrals_completed_count' => $this->referralsCompletedCount($user),
            default => 0,
        };
    }

    /**
     * BR-5 source (b) — deals this agent has personally brought to Complete
     * Payment, per the ONE shared ClosedDealPredicate (TASK-179 §3.1, human
     * decision D4).
     *
     * TASK-180 §3 (B3, 2026-08-13). This was
     * `where('current_stage', CompletePayment)` EXACTLY — the strictest of
     * the five stale answers to this question. Since ADR-026 added the
     * post-sale stages, advancing a deal the customer had already paid for
     * into จัดส่ง / นัดใช้บริการ / ติดตามผล (or `ongoing_next_meeting`)
     * REMOVED it from a badge condition the agent had already earned
     * progress toward: their count went DOWN because their work moved
     * forward. Correcting it makes the count monotonic, which is the only
     * behaviour a gamification metric can honestly have.
     *
     * USER-VISIBLE CONSEQUENCE, deliberately not suppressed: this makes
     * `referrals_completed_count` go UP for any agent with post-payment
     * deals, so agents can become newly eligible for badges the evaluator
     * was withholding. That is them receiving what they earned, not an
     * inflation. No grandfather clause is applied here — see TASK-180 §3's
     * flag; the decision to ship belongs to ag-lead/the human, not to this
     * class.
     *
     * BR-6: filtered by the agent's OWN company_id explicitly, not left to
     * TenantScope. This evaluator runs from BadgeAutoAwardService, which is
     * called from domain events and can therefore execute with NO
     * authenticated user (queue worker / console command), and TenantScope
     * is a no-op when auth()->user() is null. `agent_id` alone is not a
     * tenant filter either: a user moved between companies (Phase 11)
     * keeps their id, so their previous company's referrals would otherwise
     * still count towards a badge in the new one.
     */
    private function referralsCompletedCount(User $user): int
    {
        $query = Referral::query()
            ->where('referrals.company_id', $user->company_id) // BR-6
            ->where('referrals.agent_id', $user->id);

        ClosedDealPredicate::apply($query);

        return (int) $query->count();
    }
}
