<?php

namespace App\Policies;

use App\Models\RewardItem;
use App\Models\User;

// Reward catalog DEFINITIONS are non-sensitive shared reference data —
// same "any authenticated user may list, own-company-or-platform-default
// authoring" shape as BadgePolicy, verbatim.
class RewardItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * SECURITY AUDIT 2026-08-21 — this returned true for EVERYONE.
     *
     * Same hole as BadgePolicy::view(), and for the same reason: the class
     * comment copies BadgePolicy "verbatim", so it copied the gap too.
     * cost_points and stock_quantity are a company's reward economics —
     * what it pays for loyalty and how much of it is left — and any
     * authenticated agent at any other company could read them by walking
     * sequential ids. Proved by test, not inferred.
     *
     * RewardItemController::index() has always filtered to "own company OR
     * platform default"; show() did not. This is that filter.
     */
    public function view(User $user, RewardItem $rewardItem): bool
    {
        return $user->isSuperAdmin()
            || $rewardItem->company_id === null
            || $rewardItem->company_id === $user->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, RewardItem $rewardItem): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isCompanyAdmin() && $rewardItem->company_id === $user->company_id;
    }

    public function delete(User $user, RewardItem $rewardItem): bool
    {
        return $this->update($user, $rewardItem);
    }
}
