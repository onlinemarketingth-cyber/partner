<?php

namespace App\Services\Gamification;

use App\Models\User;
use App\Models\UserBadge;

// Manual badge-awarding (Company Admin/Super Admin action). Automatic
// condition-based awarding off Badge.condition_config is explicitly
// OUT OF SCOPE (ERD-001 open question #9 — no interpreter exists, and
// none is invented here per CLAUDE.md Section 8 rule 1).
class UserBadgeService
{
    /**
     * firstOrCreate respects the DB-level UNIQUE(user_id, badge_id)
     * constraint gracefully — awarding the same badge twice is a
     * no-op, not an error, same idempotency idiom used throughout
     * Phase 6 (ModuleCompletion, UserCertification).
     */
    public function award(int $userId, int $badgeId): UserBadge
    {
        $targetUser = User::findOrFail($userId);

        return UserBadge::firstOrCreate(
            ['user_id' => $userId, 'badge_id' => $badgeId],
            ['company_id' => $targetUser->company_id, 'earned_at' => now()]
        );
    }
}
