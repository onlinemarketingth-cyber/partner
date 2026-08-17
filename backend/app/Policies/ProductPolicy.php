<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

// Mirrors BrandPolicy — Agent can browse (needed for Referral submission
// later), only Company Admin/Super Admin can manage.
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return $user->isSuperAdmin() || $user->company_id === $product->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $product->company_id);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->update($user, $product);
    }
}
