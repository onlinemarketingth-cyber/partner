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
        return $user->isSuperAdmin() || $user->company_id === $productCategory->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, ProductCategory $productCategory): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $productCategory->company_id);
    }

    public function delete(User $user, ProductCategory $productCategory): bool
    {
        return $this->update($user, $productCategory);
    }
}
