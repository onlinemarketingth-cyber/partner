<?php

namespace App\Policies;

use App\Models\CommissionMatrixLevelRate;
use App\Models\User;

// ADR-011/TASK-030 — same access shape as CommissionRulePolicy/
// CommissionOverrideRulePolicy: sensitive compensation config, Company
// Admin/Super Admin only, no Agent read access at all.
class CommissionMatrixLevelRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, CommissionMatrixLevelRate $commissionMatrixLevelRate): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $commissionMatrixLevelRate->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, CommissionMatrixLevelRate $commissionMatrixLevelRate): bool
    {
        return $this->view($user, $commissionMatrixLevelRate);
    }

    public function delete(User $user, CommissionMatrixLevelRate $commissionMatrixLevelRate): bool
    {
        return $this->view($user, $commissionMatrixLevelRate);
    }
}
