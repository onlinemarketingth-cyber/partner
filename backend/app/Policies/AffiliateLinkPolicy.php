<?php

namespace App\Policies;

use App\Models\AffiliateLink;
use App\Models\User;

// ADR-011/TASK-032 — Section 5 rule 4: an Agent manages only their OWN
// links (agent_id = self); Company Admin sees/manages every link within
// their own company (Section 2: "Company Admin — manages data within
// their own company only"); Super Admin cross-company.
class AffiliateLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // scoped to "own" (Agent) or "own company" (Admin) inside the Controller/query, same as ReferralPolicy.
    }

    public function view(User $user, AffiliateLink $affiliateLink): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isCompanyAdmin()) {
            return $user->company_id === $affiliateLink->company_id;
        }

        return $user->id === $affiliateLink->agent_id;
    }

    public function create(User $user): bool
    {
        return true; // any Agent/Admin may attempt to mint a link; BR-1 (must hold Basic cert) is enforced in AffiliateLinkService, not here.
    }

    public function delete(User $user, AffiliateLink $affiliateLink): bool
    {
        return $this->view($user, $affiliateLink);
    }
}
