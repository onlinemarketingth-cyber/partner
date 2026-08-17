<?php

namespace App\Policies;

use App\Models\User;

// Phase 9 — level_thresholds has NO company_id column at all (unlike
// GamificationRule/Badge, which are "own override or platform default").
// A row here changes XP->Level for every company on the platform at
// once, so only Super Admin may write it — a Company Admin editing "10
// XP per Level 2" would silently change every other tenant's agents'
// levels too. Reading is safe for anyone authenticated (agents need to
// see their own level/progress).
class LevelThresholdPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
