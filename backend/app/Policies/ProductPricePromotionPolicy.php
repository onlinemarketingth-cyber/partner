<?php

namespace App\Policies;

use App\Models\ProductPricePromotion;
use App\Models\User;

// Same shape as RewardItemPolicy/AnnouncementPolicy — pricing info is
// harmless shared reading material; only Company Admin/Super Admin
// author it.
class ProductPricePromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductPricePromotion $productPricePromotion): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, ProductPricePromotion $productPricePromotion): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isCompanyAdmin() && $productPricePromotion->company_id === $user->company_id;
    }

    public function delete(User $user, ProductPricePromotion $productPricePromotion): bool
    {
        return $this->update($user, $productPricePromotion);
    }
}
