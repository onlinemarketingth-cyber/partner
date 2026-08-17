<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

// Newsfeed posts are shared reading material for every agent in the
// target company — same "any authenticated user may list, own-company-
// or-platform-default authoring" shape as BadgePolicy/RewardItemPolicy.
class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Announcement $announcement): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, Announcement $announcement): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isCompanyAdmin() && $announcement->company_id === $user->company_id;
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $this->update($user, $announcement);
    }
}
