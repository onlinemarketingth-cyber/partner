<?php

namespace App\Services\Gamification;

use App\Models\Badge;
use App\Models\User;
use App\Models\UserBadge;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 10 — checks every badge a user is eligible for (their own
 * company's badges + platform-wide defaults) against
 * BadgeConditionEvaluator, and awards any that newly qualify.
 *
 * Idempotent via the same firstOrCreate + DB-level UNIQUE(user_id,
 * badge_id) pattern as the manual-award path
 * (UserBadgeService::award()) — calling this repeatedly for a user who
 * already has a badge is always a safe no-op.
 *
 * Never throws — same non-blocking philosophy as GamificationService:
 * a badly-configured condition_config must never break the action that
 * triggered this check (module completion, pipeline advance, etc.).
 */
class BadgeAutoAwardService
{
    public function __construct(private BadgeConditionEvaluator $evaluator) {}

    public function checkAndAwardForUser(User $user): void
    {
        // Manual-award-only badges (condition_config null) are excluded
        // up front — evaluate() would return false for them anyway, but
        // skipping the query entirely avoids pointless metric lookups.
        $badges = Badge::query()
            ->where(fn ($q) => $q->where('company_id', $user->company_id)->orWhereNull('company_id'))
            ->whereNotNull('condition_config')
            ->get();

        foreach ($badges as $badge) {
            try {
                $this->checkAndAwardOne($badge, $user);
            } catch (Throwable $e) {
                Log::warning("BadgeAutoAwardService: failed to evaluate badge {$badge->id} for user {$user->id}: {$e->getMessage()}");
            }
        }
    }

    private function checkAndAwardOne(Badge $badge, User $user): void
    {
        $alreadyEarned = UserBadge::where('user_id', $user->id)->where('badge_id', $badge->id)->exists();
        if ($alreadyEarned) {
            return;
        }

        if (! $this->evaluator->evaluate($badge->condition_config ?? [], $user)) {
            return;
        }

        UserBadge::firstOrCreate(
            ['user_id' => $user->id, 'badge_id' => $badge->id],
            ['company_id' => $user->company_id, 'earned_at' => now()],
        );
    }
}
