<?php

namespace App\Policies;

use App\Models\AgentInviteLink;
use App\Models\User;

// TASK-112 / ADR-025 §3 — mirrors ProductShareLinkPolicy exactly: an
// Agent manages only their OWN recruit links (agent_id = self); Company
// Admin sees/manages every link within their own company; Super Admin
// cross-company.
//
// Registered by Laravel 12's policy auto-discovery (App\Models\X ->
// App\Policies\XPolicy) — this app has no AuthServiceProvider and
// registers no policy explicitly, so nothing else is needed here.
//
// Note what this Policy deliberately does NOT decide: whether the actor
// is allowed to MINT a link at all. ADR-025 §1's `is_team_leader` gate is
// a business rule and belongs in TASK-113's AgentInviteLinkService, the
// same split ProductShareLinkPolicy::create() already documents for BR-1.
class AgentInviteLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // scoped to "own" (Agent) or "own company" (Admin) inside the Controller query (TASK-113).
    }

    public function view(User $user, AgentInviteLink $agentInviteLink): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isCompanyAdmin()) {
            return $user->company_id === $agentInviteLink->company_id;
        }

        return $user->id === $agentInviteLink->agent_id;
    }

    public function create(User $user): bool
    {
        return true; // any Agent/Admin may attempt to mint; the is_team_leader gate (ADR-025 §1) is enforced in the Service, not here.
    }

    public function delete(User $user, AgentInviteLink $agentInviteLink): bool
    {
        // "delete" is a SOFT revoke (revoked_at) in TASK-113 — a hard
        // delete would nullOnDelete every recruit's
        // recruited_via_agent_link_id and destroy attribution.
        return $this->view($user, $agentInviteLink);
    }
}
