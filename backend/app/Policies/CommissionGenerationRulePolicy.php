<?php

namespace App\Policies;

use App\Models\CommissionGenerationRule;
use App\Models\User;

// ADR-011/TASK-031 — same access shape as CommissionOverrideRulePolicy/
// CommissionMatrixLevelRatePolicy: sensitive compensation config,
// Company Admin/Super Admin only.
class CommissionGenerationRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, CommissionGenerationRule $commissionGenerationRule): bool
    {
        return $user->isSuperAdmin()
            || ($user->isCompanyAdmin() && $user->company_id === $commissionGenerationRule->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, CommissionGenerationRule $commissionGenerationRule): bool
    {
        return $this->view($user, $commissionGenerationRule);
    }

    public function delete(User $user, CommissionGenerationRule $commissionGenerationRule): bool
    {
        return $this->view($user, $commissionGenerationRule);
    }
}
