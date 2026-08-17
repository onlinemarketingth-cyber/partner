<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

// CLAUDE.md Section 5, rule 3 (Policies control authorization for every
// action) + rule 4 (visibility levels). A Company is the tenant boundary
// itself, so only Super Admin may create/update/delete it; Company
// Admin/Agent may only ever view their own company.
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Company $company): bool
    {
        return $user->isSuperAdmin() || $user->company_id === $company->id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }
}
