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

    /**
     * SECURITY AUDIT 2026-08-21 — this returned true for EVERYONE.
     *
     * Announcement carries no TenantScope, deliberately: a platform-wide
     * post has a null company_id and must reach every company. So nothing
     * else was narrowing this. AnnouncementController::show() does re-apply
     * the audience filter — but only `if ($user?->isAgent())`, and there are
     * three roles. A Company Admin fell through both gates and could read a
     * rival company's drafts, scheduled posts and cert-tier targeting by
     * walking sequential ids. Proved by test before this change, not
     * inferred from the shape of the code.
     *
     * The controller's own comment records TASK-156 closing exactly this
     * hole for Agents. The admin case was never covered by it. Closing it
     * here at the Policy rather than adding a second branch in show(),
     * because the Policy covers every caller instead of one route — and a
     * route-level fix is what left this open the first time.
     *
     * A null company_id stays readable on purpose: that is the platform's
     * own announcement, addressed to everybody.
     */
    public function view(User $user, Announcement $announcement): bool
    {
        return $user->isSuperAdmin()
            || $announcement->company_id === null
            || $announcement->company_id === $user->company_id;
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
