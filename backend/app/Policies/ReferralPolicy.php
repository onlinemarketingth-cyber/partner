<?php

namespace App\Policies;

use App\Models\Referral;
use App\Models\User;

// CLAUDE.md §2 "SWS Referral", Section 5 rule 4 (Agent sees only their
// own records — same shape as ClientPolicy, since a Referral belongs to
// the referring agent, not the whole company). BR-1 (must have passed
// Basic cert) is deliberately NOT checked here — it's a business
// precondition on the referred-to agent, resolved after agent_id is
// known, so it lives in ReferralService::create(), not this Policy.
class ReferralPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // narrowed to "own only" at the query level for Agent — see ReferralController::index
    }

    public function view(User $user, Referral $referral): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->company_id !== $referral->company_id) {
            return false;
        }

        return $user->isCompanyAdmin() || $referral->agent_id === $user->id;
    }

    public function create(User $user): bool
    {
        // Any authenticated company member may submit a referral (Agent
        // for themselves, Company Admin/Super Admin on behalf of an
        // agent) — the real gate is BR-1, enforced in the Service
        // against the resolved referring agent, not the actor.
        return true;
    }

    // No update()/delete() — deliberately not exposed. A referral is a
    // sales-audit record; once submitted it only ever moves forward
    // through the pipeline via advance(), never free-form edited or
    // removed (design decision, see TASK-006 — flag if wrong).

    /**
     * Same visibility shape as view() — whoever can see this referral
     * may advance its pipeline stage. Named ability (not a CRUD verb)
     * because "advance" isn't update/delete; see routes/api.php.
     */
    public function advance(User $user, Referral $referral): bool
    {
        return $this->view($user, $referral);
    }

    /**
     * TASK-026 — same visibility shape as view()/advance(); the "editable
     * only until Complete Payment" time window is a business-state check
     * (ReferralService::setCoAgent()), not an authorization concern, so
     * it doesn't belong here.
     */
    public function setCoAgent(User $user, Referral $referral): bool
    {
        return $this->view($user, $referral);
    }
}
