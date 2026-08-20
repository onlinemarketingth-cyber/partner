<?php

namespace App\Policies;

use App\Models\CatalogCategory;
use App\Models\User;

// Mirrors CatalogBrandPolicy — see its docblock for the reasoning.
class CatalogCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CatalogCategory $catalogCategory): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, CatalogCategory $catalogCategory): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, CatalogCategory $catalogCategory): bool
    {
        return $user->isSuperAdmin();
    }
}
