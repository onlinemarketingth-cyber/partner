<?php

namespace App\Policies;

use App\Models\CommissionMatrixLevelRate;
use App\Models\User;

// ADR-011/TASK-030 — same access shape as CommissionRulePolicy/
// CommissionOverrideRulePolicy: sensitive compensation config, Company
// Admin/Super Admin only, no Agent read access at all.
//
// 2026-09-11 (owner decision) — writes narrowed to Super Admin, with
// reads untouched; see CommissionRulePolicy's docblock for the reasoning
// and the cost. A per-level matrix rate is a commission rate, so it
// moves with the rest of the family.
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
        return $user->isSuperAdmin();
    }

    public function update(User $user, CommissionMatrixLevelRate $commissionMatrixLevelRate): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, CommissionMatrixLevelRate $commissionMatrixLevelRate): bool
    {
        return $user->isSuperAdmin();
    }
}
