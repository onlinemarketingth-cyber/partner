<?php

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;

// Mirrors BrandPolicy — see its comment for the reasoning.
class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductCategory $productCategory): bool
    {
        // TASK-253 / ADR-040 — a platform-owned row (company_id null) is
        // readable by every company: a shared product points at it, so
        // refusing the read would break the product it classifies.
        if ($productCategory->company_id === null) {
            return true;
        }

        return $user->isSuperAdmin() || $user->company_id === $productCategory->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, ProductCategory $productCategory): bool
    {
        // TASK-253 / ADR-040 — writing a platform-owned row is Super Admin
        // only. `$user->company_id === null` is never true for a Company
        // Admin, so the existing expression already refuses it; this is
        // written out rather than relied upon, because "it happens to be
        // false" is not a rule anybody can read.
        if ($productCategory->company_id === null) {
            return $user->isSuperAdmin();
        }

        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $productCategory->company_id);
    }

    public function delete(User $user, ProductCategory $productCategory): bool
    {
        return $this->update($user, $productCategory);
    }

    /**
     * 2026-09-09 — bringing a hidden row back is the same authority as
     * hiding it, deliberately: anyone who could not delete it has no
     * business un-deleting it either, and for a platform-owned row that
     * means Super Admin alone (see update()).
     */
    public function restore(User $user, ProductCategory $productCategory): bool
    {
        return $this->delete($user, $productCategory);
    }
}
