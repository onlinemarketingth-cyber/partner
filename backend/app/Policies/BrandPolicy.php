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
        // TASK-253 / ADR-040 — a platform-owned row (company_id null) is
        // readable by every company: a shared product points at it, so
        // refusing the read would break the product it classifies.
        if ($brand->company_id === null) {
            return true;
        }

        return $user->isSuperAdmin() || $user->company_id === $brand->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, Brand $brand): bool
    {
        // TASK-253 / ADR-040 — writing a platform-owned row is Super Admin
        // only. `$user->company_id === null` is never true for a Company
        // Admin, so the existing expression already refuses it; this is
        // written out rather than relied upon, because "it happens to be
        // false" is not a rule anybody can read.
        if ($brand->company_id === null) {
            return $user->isSuperAdmin();
        }

        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $brand->company_id);
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->update($user, $brand);
    }

    /**
     * 2026-09-09 — bringing a hidden row back is the same authority as
     * hiding it, deliberately: anyone who could not delete it has no
     * business un-deleting it either, and for a platform-owned row that
     * means Super Admin alone (see update()).
     */
    public function restore(User $user, Brand $brand): bool
    {
        return $this->delete($user, $brand);
    }
}
