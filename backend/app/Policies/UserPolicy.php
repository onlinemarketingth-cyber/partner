<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Registration\LeaderRecruitScope;
use App\Support\SuperAdminGuard;

// "Manage Agents" (CLAUDE.md §10 ag-lead task, human-confirmed scope
// this phase): Company Admin manages team members (agent + company_admin
// role) within their own company only — never a Super Admin row, and
// never across companies. Super Admin manages across every company.
//
// ── 2026-09-18 — A SUPER ADMIN MAY NOW SEE AND MANAGE ANOTHER ONE ──
//
// This paragraph replaces the one that used to end this docblock:
// "Creating a NEW Super Admin account is deliberately NOT possible via
// this API at all (see StoreUserRequest) — that's an out-of-band/manual
// action, too sensitive for a same-tier 'add teammate' flow."
//
// The owner asked for the opposite ("เพิ่มสิทธิ์ Super Admin ในการเพิ่มและ
// แก้ไข user"), having been told what the old sentence was protecting:
// `artisan admin:create-super` needs SSH to the production host, which
// means every platform-owner change waits on the one person holding the
// key. The old rule made a real operational problem out of a
// theoretical one.
//
// WHAT DID NOT CHANGE, and is now the whole of the protection:
//   * ONLY a Super Admin may see, create, promote or demote one. A
//     Company Admin's view of a Super Admin row is still false, exactly
//     as before — the widening is same-tier, never downward.
//   * The LAST active Super Admin cannot be demoted or deactivated
//     (SuperAdminGuard), and nobody may demote themselves
//     (UpdateUserRequest) — the two ways this endpoint could otherwise
//     have locked every administrator out of the platform.
//   * move() still refuses a Super Admin target: they have no
//     company_id to move between, which is the point of the role.
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
            // 2026-09-18 — was `return false` unconditionally ("never
            // exposed via this resource, even to another Super Admin —
            // platform admins aren't 'team members' to browse").
            //
            // A Super Admin may now see another one, because they now have
            // to: the screen that creates and demotes them has to list them,
            // and a role you can grant but never see afterwards is one
            // nobody can audit. A Company Admin still cannot — this returns
            // before the tenant comparison below, so the widening reaches
            // exactly one caller and no further.
            return $user->isSuperAdmin();
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
        if ($this->isCommissionHouseAccount($target)) {
            return false;
        }

        return $this->view($user, $target);
    }

    /**
     * 2026-09-15 — THE COMPANY'S OWN SEAT IN ITS HIERARCHY IS NOT A PERSON.
     *
     * A company can place itself at the top of its manager chain and be paid
     * a leader's override (ขั้นตอนที่ 4.2). The seat is a real `users` row —
     * that is the whole design, so the payout walk needs no special case —
     * which means it turns up in the user-management list with the full set
     * of buttons a person gets: edit, reset password, move company,
     * deactivate. Every one of them is a way for the company's own margin to
     * stop arriving, or to arrive somewhere else.
     *
     * REFUSED IN THE POLICY, not in each screen. This codebase's user list
     * states its own rule — "every button is gated on the SERVER's answer for
     * this row, never on a rule re-derived here" — and four `v-if`s carrying a
     * copy of this condition is four places for the fifth screen to forget it.
     *
     * Switching the seat on and off is CommissionHouseAccountService's job.
     * It has to move every agent's `manager_id` in the same breath, which none
     * of the controls above do.
     */
    private function isCommissionHouseAccount(User $target): bool
    {
        return $target->isCommissionHouseAccount();
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
        if ($this->isCommissionHouseAccount($target)) {
            return false;
        }

        if ($user->id === $target->id) {
            // Never allow deactivating your own account through this
            // endpoint — an obvious self-lockout risk, same defensive
            // shape as the self-dealing exclusions elsewhere (e.g.
            // UserBadgePolicy::award()).
            return false;
        }

        // 2026-09-18 — the OTHER self-lockout, and the one the rule above
        // does not cover: closing the last remaining Super Admin's account
        // locks every administrator out at once, including the person
        // clicking. Unreachable before Super Admin rows became visible here
        // at all — see SuperAdminGuard.
        if (SuperAdminGuard::isLastActive($target)) {
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
        return $user->isSuperAdmin()
            && ! $target->isSuperAdmin()
            && ! $this->isCommissionHouseAccount($target);
    }
}
