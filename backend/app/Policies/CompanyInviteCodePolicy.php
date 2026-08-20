<?php

namespace App\Policies;

use App\Models\CompanyInviteCode;
use App\Models\User;

/**
 * TASK-233 — who may hand out a company's signup link.
 *
 * SUPER ADMIN AND COMPANY ADMIN, and no one else.
 *
 * Company Admin is included on purpose, and it is the decision worth
 * explaining. This link is recruitment material: it goes on the flyer, on
 * the branch office sign, in the Facebook post. That is the same category
 * of thing as the company's own theme, logo and branded login link, all of
 * which a Company Admin already controls without asking the platform
 * operator. Routing it through Super Admin instead would mean a company
 * cannot run a recruitment campaign without filing a ticket, and the
 * predictable result is one permanent link that nobody dares expire —
 * which is strictly worse for security than letting them mint a fresh one
 * per campaign.
 *
 * AGENTS ARE EXCLUDED, and that is not an oversight either. An agent
 * recruiting into their own downline already has `agent_invite_links`
 * (ADR-025), which is gated on the admin-granted `is_team_leader` flag and
 * attributes every recruit to them. A company-wide link attributes to
 * nobody. Handing that to an agent would let any agent bypass the team
 * leader gate entirely by recruiting through the company's front door.
 */
class CompanyInviteCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, CompanyInviteCode $code): bool
    {
        return $this->sameCompanyOrPlatform($user, $code);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, CompanyInviteCode $code): bool
    {
        return $this->sameCompanyOrPlatform($user, $code);
    }

    public function delete(User $user, CompanyInviteCode $code): bool
    {
        return $this->sameCompanyOrPlatform($user, $code);
    }

    /**
     * BR-6 — a Company Admin reaches only their own company's codes.
     *
     * Checked here as well as by TenantScope rather than trusting the
     * scope alone: this model is deliberately NOT tenant-scoped (see the
     * model's own comment — it was Super-Admin-only when it was written),
     * so the scope is not standing behind this the way it does elsewhere.
     * The moment a Company Admin can reach the model at all, the ownership
     * check has to be here.
     */
    private function sameCompanyOrPlatform(User $user, CompanyInviteCode $code): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isCompanyAdmin() && (int) $user->company_id === (int) $code->company_id;
    }
}
