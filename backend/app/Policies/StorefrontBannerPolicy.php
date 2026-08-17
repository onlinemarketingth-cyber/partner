<?php

namespace App\Policies;

use App\Models\StorefrontBanner;
use App\Models\User;

// TASK-068 / ADR-020 row 2. Same shape as ProductCategoryPolicy/
// AnnouncementPolicy: any authenticated company member may read (the
// Agent Portal renders the banner carousel), only Company Admin/Super
// Admin may write.
class StorefrontBannerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StorefrontBanner $storefrontBanner): bool
    {
        return $user->isSuperAdmin() || $user->company_id === $storefrontBanner->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, StorefrontBanner $storefrontBanner): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $storefrontBanner->company_id);
    }

    public function delete(User $user, StorefrontBanner $storefrontBanner): bool
    {
        return $this->update($user, $storefrontBanner);
    }
}
