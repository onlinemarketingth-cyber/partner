<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\RejectAgentRequest;
use App\Http\Resources\PendingRecruitResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Registration\AgentApprovalService;
use App\Support\CompanyScopeFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

// TASK-020 (ADR-005 decision 3) — Company Admin approves/rejects their
// own company's self-registered Agents; Super Admin sees/acts across
// companies. TenantScope already narrows User::query() for Company
// Admin the same way every other Admin listing in this codebase does
// (see UserController's own comment) — no ad-hoc `where('company_id', ...)`
// added here (Section 5 rule 2).
//
// Reuses UserPolicy::viewAny()/update() rather than a brand-new Policy
// class — same "Company Admin same company / Super Admin any / never a
// Super Admin target" authorization shape already enforced there for
// the sibling "Manage Agents" screen; TASK-020's own spec left this as
// an ag-dev judgment call, not a business rule to invent.
//
// TASK-115 / ADR-025 §7 widens exactly ONE action here — approve() — to a
// second actor type (a designated team leader, scoped to their own
// recruits), and adds two endpoints around it:
//   * myRecruits() — the leader's own pending list (TASK-116 point 3);
//   * revoke()     — admin-only reversal of an approval, the thing that
//                    makes ADR-025 §7's "Company Admins ... can reverse
//                    anything a leader did" actually true.
// reject() is deliberately NOT widened — see AgentApprovalService's class
// docblock for the four reasons.
class AgentApprovalController extends Controller
{
    /**
     * The Admin queue. Defaults to Pending — unchanged from TASK-020, so
     * every existing caller and test sees exactly what it saw before.
     *
     * TASK-115 adds an OPTIONAL `?status=` so TASK-117's queue can also show
     * recently-decided registrants; without it there is nowhere for an Admin
     * to ever see "leader-approved", because an approved user leaves the
     * pending list the instant the leader presses the button, and that
     * visibility is the mitigation ADR-025 §7 leans on.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,approved,rejected'],
        ]);

        $status = AgentApprovalStatus::from($validated['status'] ?? AgentApprovalStatus::Pending->value);

        $query = User::query()
            ->where('agent_approval_status', $status)
            // approvedBy powers UserResource's `approved_by` block — the
            // "with the approver named" half of ADR-025 §7's mitigation.
            // Eager-loaded so a queue of N rows is not N+1 lookups.
            ->with(['company', 'approvedBy'])
            ->orderBy('created_at');

        // TASK-209 — Super Admin's header company scope. Applied BEFORE
        // paginate(): narrowing a paginator after the fact would page over
        // the unfiltered set.
        CompanyScopeFilter::apply($query, $request);

        return UserResource::collection($query->paginate());
    }

    /**
     * TASK-115 / TASK-116 point 3 — a team leader's OWN pending recruits.
     *
     * Self-scoped like MeController/MeTeamController: the caller is always
     * $request->user(), never a {user} identifying whose list to show, so
     * there is no IDOR surface to guard. The set returned is defined by
     * LeaderRecruitScope::pendingRecruitsQuery(), the same rule
     * UserPolicy::approveRegistration() enforces on the button — a row can
     * never appear here and then 403 on approve.
     *
     * 403 (not an empty list) for a non-leader: ADR-025 §2 makes losing the
     * flag stop recruiting immediately, and a leader who was de-flagged
     * while holding pending recruits must be told the capability is gone,
     * not shown an empty screen they cannot explain.
     *
     * PendingRecruitResource, NOT UserResource — a leader is not entitled to
     * a teammate's email/phone/national ID (ADR-024 §3's line, restated in
     * TASK-115's spec). See that Resource's docblock.
     */
    public function myRecruits(Request $request, AgentApprovalService $service): AnonymousResourceCollection
    {
        $leader = $request->user();

        abort_unless($leader->is_team_leader, 403, 'เฉพาะหัวหน้าทีมเท่านั้นที่ดูรายชื่อผู้สมัครในทีมได้');

        return PendingRecruitResource::collection(
            $service->pendingRecruitsFor($leader)->paginate()
        );
    }

    /**
     * Admin OR the target's own team leader (ADR-025 §7). The two branches
     * live in UserPolicy::approveRegistration(); which one applied is
     * recorded on the row as `approval_source` by the Service.
     *
     * Note what did NOT change: the route still route-model-binds {user}
     * through TenantScope, so a cross-company target 404s before the Policy
     * runs at all (BR-6, same behaviour TASK-020's test already locks in).
     */
    public function approve(Request $request, User $user, AgentApprovalService $service): UserResource
    {
        $this->authorize('approveRegistration', $user);

        return new UserResource(
            $service->approve($user, $request->user())->load(['company', 'approvedBy'])
        );
    }

    /**
     * ADMIN ONLY — authorizes against UserPolicy::update(), NOT
     * approveRegistration(). A team leader may approve their own recruits
     * and nothing else; see AgentApprovalService's class docblock.
     */
    public function reject(RejectAgentRequest $request, User $user, AgentApprovalService $service): UserResource
    {
        $this->authorize('update', $user);

        return new UserResource(
            $service->reject($user, $request->validated('reason'), $request->user())->load('company')
        );
    }

    /**
     * TASK-115 / ADR-025 §7 — admin-only reversal of an approval, including
     * one a team leader made. Same Form Request as reject() (an optional
     * reason, max 1000 chars) because the payload is identical; the verbs
     * are separate because their preconditions are (Pending vs Approved) and
     * because an auditor must be able to tell "we declined an application"
     * from "we overturned an admission".
     */
    public function revoke(RejectAgentRequest $request, User $user, AgentApprovalService $service): UserResource
    {
        $this->authorize('update', $user);

        return new UserResource(
            $service->revoke($user, $request->validated('reason'), $request->user())->load('company')
        );
    }
}
