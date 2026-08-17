<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserBadge;

// Same "own records only for Agent" shape as CommissionLedgerPolicy/
// XpLedgerPolicy. No create()/update()/delete() — award() below is the
// one deliberately narrow write path (Company Admin/Super Admin only,
// same reasoning as CommissionLedgerPolicy::markPaid() — an agent
// awarding their own badge would be self-dealing).
class UserBadgePolicy
{
    public function viewAny(User $user): bool
    {
        return true; // narrowed to "own only" at the query level for Agent — see UserBadgeController::index
    }

    public function view(User $user, UserBadge $userBadge): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $userBadge->company_id) {
            return false;
        }

        return $user->isCompanyAdmin() || $userBadge->user_id === $user->id;
    }

    public function award(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }
}
