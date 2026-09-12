<?php

namespace App\Policies;

use App\Models\CommissionOverrideRule;
use App\Models\User;

// TASK-025 / ADR-006 — same access shape as CommissionRulePolicy:
// sensitive compensation config, Company Admin/Super Admin only, no
// Agent read access at all (an Agent never needs to see manager
// override rates, only their own commission_ledger entries).
//
// 2026-09-11 (owner decision) — writes narrowed to Super Admin, with
// reads untouched; see CommissionRulePolicy's docblock for the reasoning
// and the cost. An override rate is a commission rate like any other in
// this family, so it moves with them rather than becoming the one rate
// table a Company Admin could still edit.
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
        return $user->isSuperAdmin();
    }

    public function update(User $user, CommissionOverrideRule $commissionOverrideRule): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, CommissionOverrideRule $commissionOverrideRule): bool
    {
        return $user->isSuperAdmin();
    }
}
