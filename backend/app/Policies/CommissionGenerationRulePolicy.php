<?php

namespace App\Policies;

use App\Models\CommissionGenerationRule;
use App\Models\User;

// ADR-011/TASK-031 — same access shape as CommissionOverrideRulePolicy/
// CommissionMatrixLevelRatePolicy: sensitive compensation config,
// Company Admin/Super Admin only.
//
// 2026-09-11 (owner decision) — writes narrowed to Super Admin, with
// reads untouched; see CommissionRulePolicy's docblock for the reasoning
// and the cost. A per-generation rate is a commission rate, so it moves
// with the rest of the family.
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
        return $user->isSuperAdmin();
    }

    public function update(User $user, CommissionGenerationRule $commissionGenerationRule): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, CommissionGenerationRule $commissionGenerationRule): bool
    {
        return $user->isSuperAdmin();
    }
}
