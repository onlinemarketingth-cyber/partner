<?php

namespace App\Policies;

use App\Models\ClientCategory;
use App\Models\User;

// TASK-056 Sprint P2 — mirrors BrandPolicy exactly: browsable by anyone
// in the company (an Agent needs the list to filter/search their own
// clients); only Company Admin/Super Admin may manage it.
class ClientCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // TenantScope already restricts the query to the user's own company
    }

    public function view(User $user, ClientCategory $clientCategory): bool
    {
        return $user->isSuperAdmin() || $user->company_id === $clientCategory->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, ClientCategory $clientCategory): bool
    {
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $clientCategory->company_id);
    }

    public function delete(User $user, ClientCategory $clientCategory): bool
    {
        return $this->update($user, $clientCategory);
    }
}
