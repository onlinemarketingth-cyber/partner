<?php

namespace App\Policies;

use App\Models\ProductShareLink;
use App\Models\User;

// TASK-056 Sprint P1 — mirrors AffiliateLinkPolicy exactly: an Agent
// manages only their OWN share links (agent_id = self); Company Admin
// sees/manages every link within their own company; Super Admin
// cross-company.
class ProductShareLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // scoped to "own" (Agent) or "own company" (Admin) inside the Controller query.
    }

    public function view(User $user, ProductShareLink $productShareLink): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isCompanyAdmin()) {
            return $user->company_id === $productShareLink->company_id;
        }

        return $user->id === $productShareLink->agent_id;
    }

    public function create(User $user): bool
    {
        return true; // any Agent/Admin may attempt to mint; BR-1 (Basic cert) is enforced in ProductShareLinkService, not here.
    }

    public function delete(User $user, ProductShareLink $productShareLink): bool
    {
        return $this->view($user, $productShareLink);
    }
}
