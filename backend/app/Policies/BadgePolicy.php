<?php

namespace App\Policies;

use App\Models\Badge;
use App\Models\User;

// Badge DEFINITIONS (the catalog of what badges exist, what they look
// like, and — Phase 10 — their condition_config) are non-sensitive
// shared reference data — any authenticated user may list them, same
// "company override or platform default" nullable company_id shape as
// GamificationRule. Write access mirrors GamificationRulePolicy exactly:
// Company Admin may author their own company's badges; only Super Admin
// may author/edit a platform-wide default (company_id null).
class BadgePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Badge $badge): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, Badge $badge): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isCompanyAdmin() && $badge->company_id === $user->company_id;
    }

    public function delete(User $user, Badge $badge): bool
    {
        return $this->update($user, $badge);
    }
}
