<?php

namespace App\Policies;

use App\Models\CommissionOverrideRule;
use App\Models\User;

// TASK-025 / ADR-006 — same access shape as CommissionRulePolicy:
// sensitive compensation config, Company Admin/Super Admin only, no
// Agent read access at all (an Agent never needs to see manager
// override rates, only their own commission_ledger entries).
class CommissionOverrideRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, CommissionOverrideRule $commissionOverrideRule): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $commissionOverrideRule->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, CommissionOverrideRule $commissionOverrideRule): bool
    {
        return $this->view($user, $commissionOverrideRule);
    }

    public function delete(User $user, CommissionOverrideRule $commissionOverrideRule): bool
    {
        return $this->view($user, $commissionOverrideRule);
    }
}
