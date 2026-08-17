<?php

namespace App\Policies;

use App\Models\RewardRedemption;
use App\Models\User;

// An Agent may request a redemption for themselves and see only their
// own requests (Section 5 rule 4 "own records only"); Company
// Admin/Super Admin see every request in-company to work the approval
// queue. No update()/delete() — status only changes through decide()
// (approve/reject/fulfill), same deliberately-narrow-exception shape as
// CommissionLedgerPolicy::markPaid().
class RewardRedemptionPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // narrowed to "own only" for Agent at the query level — see RewardRedemptionController::index
    }

    public function view(User $user, RewardRedemption $rewardRedemption): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $rewardRedemption->company_id) {
            return false;
        }

        return $user->isCompanyAdmin() || $rewardRedemption->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAgent();
    }

    /** Approve / reject / fulfill — Company Admin/Super Admin only, never the requesting Agent themselves. */
    public function decide(User $user, RewardRedemption $rewardRedemption): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $rewardRedemption->company_id);
    }
}
