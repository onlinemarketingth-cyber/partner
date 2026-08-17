<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

// CLAUDE.md Section 5 rule 3/4. Catalog is browsable by anyone in the
// company (Agent needs it to know what they can sell); only Company
// Admin/Super Admin may manage it (Section 2: "Company Admin — Manages
// data within their own company only").
class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // TenantScope already restricts the query to the user's own company
    }

    public function view(User $user, Brand $brand): bool
    {
        return $user->isSuperAdmin() || $user->company_id === $brand->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $brand->company_id);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->update($user, $brand);
    }
}
