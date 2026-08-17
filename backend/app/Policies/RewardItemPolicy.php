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

    public function view(User $user, RewardItem $rewardItem): bool
    {
        return true;
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
