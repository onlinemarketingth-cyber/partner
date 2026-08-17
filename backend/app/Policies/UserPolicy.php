<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Registration\LeaderRecruitScope;

// "Manage Agents" (CLAUDE.md §10 ag-lead task, human-confirmed scope
// this phase): Company Admin manages team members (agent + company_admin
// role) within their own company only — never a Super Admin row, and
// never across companies. Super Admin manages across every company.
// Creating a NEW Super Admin account is deliberately NOT possible via
// this API at all (see StoreUserRequest) — that's an out-of-band/manual
// action, too sensitive for a same-tier "add teammate" flow.
class UserPolicy
{
    // Resolved from the container (Laravel instantiates Policies through it),
    // so the leader-scope rule has exactly one definition — see
    // LeaderRecruitScope's docblock for why it is not a private method here.
    public function __construct(private readonly LeaderRecruitScope $leaderRecruitScope) {}

    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function view(User $user, User $target): bool
    {
        if ($target->isSuperAdmin()) {
            // Never exposed via this resource, even to another Super
            // Admin — platform admins aren't "team members" to browse.
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isCompanyAdmin() && $user->company_id === $target->company_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isCompanyAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $this->view($user, $target);
    }

    /**
     * TASK-115 / ADR-025 §7 — who may flip a PENDING registration to
     * APPROVED.
     *
     * Two disjoint branches, in this order:
     *   1. update() — the pre-existing admin path, byte-for-byte unchanged.
     *      A Company Admin keeps full power over their own company and a
     *      Super Admin across companies, including over a registrant a team
     *      leader has already touched ("Company Admins keep the full
     *      approval queue and can reverse anything a leader did", ADR-025 §7).
     *   2. The narrow leader carve-out.
     *
     * DELIBERATELY NOT APPLIED TO reject(). AgentApprovalController::reject()
     * still authorizes against update(), i.e. admins only. See
     * AgentApprovalService::reject()'s docblock for the reasoning — this is
     * the enforcement point for that decision, and widening this method to
     * cover rejection would silently grant it.
     */
    public function approveRegistration(User $user, User $target): bool
    {
        if ($this->update($user, $target)) {
            return true;
        }

        return $this->leaderRecruitScope->mayApprove($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->id === $target->id) {
            // Never allow deactivating your own account through this
            // endpoint — an obvious self-lockout risk, same defensive
            // shape as the self-dealing exclusions elsewhere (e.g.
            // UserBadgePolicy::award()).
            return false;
        }

        return $this->view($user, $target);
    }

    public function restore(User $user, User $target): bool
    {
        return $this->view($user, $target);
    }

    /**
     * Phase 11 — moving a user to a different company changes tenant
     * isolation for every future query against them (BR-6/Section 5),
     * so this is deliberately narrower than update(): Super Admin only,
     * and never on another Super Admin (Super Admin has no company_id
     * to move between in the first place).
     */
    public function move(User $user, User $target): bool
    {
        return $user->isSuperAdmin() && ! $target->isSuperAdmin();
    }
}
