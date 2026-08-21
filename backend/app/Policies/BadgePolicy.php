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

    /**
     * SECURITY AUDIT 2026-08-21 — this returned true for EVERYONE, and the
     * comment above explains why that was believed to be fine.
     *
     * The belief was out of date. "Non-sensitive shared reference data" was
     * true of a badge's name and icon; it stopped being true when Phase 10
     * added condition_config — the rules that decide who earns what, which
     * is a competitor's incentive design. Badge is not TenantScope'd (a
     * platform default has a null company_id and must reach everyone), so
     * nothing else stood between an agent at company B and company A's
     * private badges by sequential id. Proved by test, not inferred.
     *
     * BadgeController::index() has always filtered to "own company OR
     * platform default" — eight lines above show(), which did not. The
     * shape below is that same filter, and it is deliberately identical to
     * GamificationRulePolicy::view(), the sibling that already had it
     * right. This was an inconsistency between two files, never a decision.
     */
    public function view(User $user, Badge $badge): bool
    {
        return $user->isSuperAdmin()
            || $badge->company_id === null
            || $badge->company_id === $user->company_id;
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
