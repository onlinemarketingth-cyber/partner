<?php

namespace App\Services\Registration;

use App\Enums\AgentApprovalStatus;
use App\Models\AgentInviteLink;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * TASK-115 / ADR-025 §7 — the ONE definition of "this pending registrant is
 * this team leader's own recruit".
 *
 * It exists as its own class, rather than as a private method on either
 * UserPolicy or AgentApprovalService, because BOTH need it and they must
 * never be able to disagree:
 *   * UserPolicy::approveRegistration() asks it to decide 403-vs-allowed;
 *   * AgentApprovalService::approve() asks it again as defense in depth, so
 *     the Service cannot be driven into a leader approval from some future
 *     caller that forgot to authorize;
 *   * AgentApprovalController::myRecruits() uses the query form of the SAME
 *     rule, so the list a leader is shown and the set they may act on are
 *     provably identical. A leader must never see a row in their queue that
 *     403s when they press the button.
 *
 * ADR-025 §7 states the scope exactly: `is_team_leader = true` AND the
 * target's `manager_id = self` AND the target arrived via one of THIS
 * leader's links AND the target is currently `pending`. Anything else → 403.
 */
final class LeaderRecruitScope
{
    /**
     * All conditions ANDed. Any single false → the caller must 403.
     *
     * Ordered cheapest-first so the single DB query at the end runs only for
     * an actor who has already passed every in-memory check.
     */
    public function mayApprove(User $actor, User $target): bool
    {
        // ADR-025 §1/§2 — the admin-granted capability. Revoking the flag
        // stops recruiting AND stops approving, immediately, even for
        // recruits already in the queue.
        if (! $actor->is_team_leader) {
            return false;
        }

        // This method answers the LEADER branch only. An admin actor is
        // handled by UserPolicy::update() (the pre-existing path), and
        // routing them through here as well would silently mislabel their
        // approval as approval_source = team_leader. Being explicit also
        // means an admin who happens to carry the flag keeps admin powers
        // rather than being narrowed to their own recruits.
        if ($actor->isSuperAdmin() || $actor->isCompanyAdmin()) {
            return false;
        }

        // Self-approval is impossible anyway (assertValidManager forbids
        // manager_id = self), but stating it means the guarantee does not
        // depend on a rule enforced in a different file.
        if ($actor->id === $target->id) {
            return false;
        }

        // BR-6, belt and braces. TenantScope has already narrowed the route
        // binding to the actor's company, so a cross-tenant target 404s
        // before this runs — but this class is also called from the Service,
        // which has no route binding to lean on.
        if ($actor->company_id === null || $actor->company_id !== $target->company_id) {
            return false;
        }

        // "their own tree" — ADR-025 §7. Note this is manager_id, the LIVE
        // hierarchy, not the link attribution: if an Admin has since re-pointed
        // this recruit to a different manager, the original leader loses the
        // right to approve them. That is the correct reading of "the leader's
        // own team roster".
        if ($target->manager_id !== $actor->id) {
            return false;
        }

        // A leader may only decide an UNDECIDED registration. This is also
        // what makes "a leader approving an already-approved user → 403"
        // true (rather than the Service's 422), per the TASK-115 spec.
        if ($target->agent_approval_status !== AgentApprovalStatus::Pending) {
            return false;
        }

        // ADR-025 §7's fourth condition — arrived through one of THIS
        // leader's links. Without it, a leader could approve anyone an Admin
        // happened to place under them, which is a strictly wider power than
        // the human agreed to.
        if ($target->recruited_via_agent_link_id === null) {
            return false;
        }

        // withoutGlobalScopes([TenantScope::class]) + an EXPLICIT company_id
        // predicate rather than relying on the scope: this method is called
        // from a Service that may one day run outside an HTTP request (queue
        // job, console command), where auth()->user() is null and TenantScope
        // is a no-op. Writing the tenant condition by hand here means the
        // check cannot silently widen in that context. Only TenantScope is
        // dropped — SoftDeletingScope stays, so a deleted link no longer
        // vouches for anyone.
        //
        // Deliberately NOT calling isUsable(): a revoked or exhausted link
        // still tells the truth about WHERE this recruit came from. The
        // recruit registered while it was valid; revoking it afterwards
        // stops new signups (ADR-025 §3), it does not orphan the people
        // already in the queue.
        return AgentInviteLink::withoutGlobalScopes([TenantScope::class])
            ->whereKey($target->recruited_via_agent_link_id)
            ->where('agent_id', $actor->id)
            ->where('company_id', $actor->company_id)
            ->exists();
    }

    /**
     * The query form of exactly the same rule, for the leader's own
     * "รออนุมัติ" list (TASK-116 point 3).
     *
     * Every predicate here has a one-to-one counterpart in mayApprove()
     * above. If you change one, change both — a row appearing here that then
     * 403s on approve is a UX bug the leader cannot diagnose.
     *
     * TenantScope stays ON for the User query: the caller is always an
     * authenticated leader, so it is doing real work (BR-6, Section 5 rule 2
     * — never hand-write the company filter when the scope covers it).
     *
     * @return Builder<User>
     */
    public function pendingRecruitsQuery(User $leader): Builder
    {
        return User::query()
            ->where('agent_approval_status', AgentApprovalStatus::Pending)
            ->where('manager_id', $leader->id)
            ->whereIn(
                'recruited_via_agent_link_id',
                AgentInviteLink::query()
                    ->where('agent_id', $leader->id)
                    ->select('id'),
            )
            // Powers PendingRecruitResource's `invite_link` — the leader's own
            // link label, so they can tell one recruiting campaign from
            // another. Eager-loaded here to keep the list free of N+1s.
            ->with('recruitedViaAgentLink')
            ->orderBy('created_at');
    }
}
