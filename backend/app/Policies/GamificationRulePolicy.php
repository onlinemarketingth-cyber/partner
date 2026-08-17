<?php

namespace App\Policies;

use App\Models\GamificationRule;
use App\Models\User;

// BR-5 config — same "Agent has zero read access, Company Admin/Super
// Admin only" shape as CommissionRulePolicy. Company Admin may view
// platform-wide defaults (company_id null) alongside their own
// company's overrides — they need to see what they're inheriting — but
// may only CREATE/UPDATE/DELETE their own company's override rows,
// never the platform default (that's Super Admin's domain only).
class GamificationRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, GamificationRule $gamificationRule): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isCompanyAdmin() && ($gamificationRule->company_id === $user->company_id || $gamificationRule->company_id === null);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, GamificationRule $gamificationRule): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isCompanyAdmin() && $gamificationRule->company_id === $user->company_id;
    }

    public function delete(User $user, GamificationRule $gamificationRule): bool
    {
        return $this->update($user, $gamificationRule);
    }
}
