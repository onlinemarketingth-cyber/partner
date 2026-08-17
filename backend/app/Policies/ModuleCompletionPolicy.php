<?php

namespace App\Policies;

use App\Models\ModuleCompletion;
use App\Models\User;

// Append-only log (ERD-001 §Academy) — an Agent may only ever create
// their OWN completion record; Company Admin/Super Admin can view all
// within their company for reporting. No update/delete for anyone —
// there is deliberately no method for either here, so Laravel's
// default Gate::authorize on those abilities falls through to `false`.
class ModuleCompletionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ModuleCompletion $moduleCompletion): bool
    {
        return $user->isSuperAdmin()
            || $user->id === $moduleCompletion->user_id
            || ($user->isCompanyAdmin() && $user->company_id === $moduleCompletion->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isAgent();
    }
}
