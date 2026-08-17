<?php

namespace App\Policies;

use App\Models\CommissionRule;
use App\Models\User;

// BR-2 config is sensitive compensation data — unlike the rest of the
// catalog, Agents do not get read access here. They see their own
// earnings via CommissionLedger (a separate, already-scoped domain),
// never the raw rate table. Company Admin/Super Admin only, both ways.
class CommissionRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, CommissionRule $commissionRule): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $commissionRule->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, CommissionRule $commissionRule): bool
    {
        return $this->view($user, $commissionRule);
    }

    public function delete(User $user, CommissionRule $commissionRule): bool
    {
        return $this->view($user, $commissionRule);
    }
}
