<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

// CLAUDE.md Section 5 rule 4 — the first domain in this codebase where
// Agent's visibility is narrower than "anyone in the company": clients
// are PDPA-sensitive personal/health data, so an Agent sees only
// records where referring_agent_id = self, not the whole company's
// client list (unlike Product/Module, which are shared catalog/content
// every Agent needs to browse). Company Admin/Super Admin see all
// within their scope, per the same rule.
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // narrowed to "own only" at the query level for Agent — see ClientController::index
    }

    public function view(User $user, Client $client): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $client->company_id) {
            return false;
        }

        return $user->isCompanyAdmin() || $client->referring_agent_id === $user->id;
    }

    public function create(User $user): bool
    {
        // Any authenticated company member may refer a client in —
        // this is literally what "Agent" does (CLAUDE.md §2, SWS
        // Referral). BR-1 (must have passed Basic cert) is enforced
        // separately once Referral submission exists (Phase 4) — this
        // Policy only covers the Client record itself.
        return true;
    }

    public function update(User $user, Client $client): bool
    {
        return $this->view($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        // Deliberately narrower than update — Agents can correct their
        // own client's details but not remove the record entirely
        // (PDPA/audit: a referral's existence shouldn't be erasable by
        // the person who created it). Company Admin/Super Admin only.
        return $user->isSuperAdmin() || ($user->isCompanyAdmin() && $user->company_id === $client->company_id);
    }
}
