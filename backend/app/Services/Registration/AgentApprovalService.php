<?php

namespace App\Services\Registration;

use App\Enums\AgentApprovalStatus;
use App\Enums\ApprovalSource;
use App\Enums\NotificationType;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

// TASK-020 — the actions the approval queue exposes. Both approve() and
// reject() are restricted to a currently-Pending target; "deactivate"
// (UserController::destroy(), Phase 7) already covers revoking an
// already-approved Agent's access.
//
// TASK-115 / ADR-025 §7 adds two things to this class:
//   * a SECOND actor type on approve() — a designated team leader, scoped to
//     their own recruits (LeaderRecruitScope);
//   * revoke(), an admin-only reversal, because ADR-025 §7 promises "Company
//     Admins keep the full approval queue and can reverse anything a leader
//     did" and before this task there was no way to reverse anything at all
//     (assertPending() blocks approve/reject on an already-approved row).
//
// ── DECISION: A TEAM LEADER MAY APPROVE, BUT NOT REJECT. ─────────────────
// The TASK-112 sprint spec and ADR-025 §7 both say "approve". Rejection is
// deliberately NOT extended to leaders, for four reasons:
//
//  1. Nothing is lost. A leader who does not want a recruit simply does not
//     press the button: the registrant stays Pending, stays blocked at login
//     (TASK-115's gate), and stays in the Company Admin's queue. There is no
//     capability gap to fill — only a difference in who gets to author a
//     permanent negative record.
//  2. Rejection writes users.approval_rejection_reason, which is shown
//     verbatim to the registrant by the login gate. Letting a peer author
//     text that the company appears to be saying is a materially different
//     grant from letting them wave someone through.
//  3. ADR-025 §7's accepted residual risk is one-directional — "a leader can
//     bring people into the company without an admin ever looking". Adding
//     reject would make it two-directional (a leader could quietly exclude a
//     rival's recruit) without the human ever having agreed to that. Guardrail
//     1: never invent business rules.
//  4. Asymmetry is safe here precisely because approve is the irreversible-ish
//     direction and it is the one the human explicitly asked for, while the
//     safe default (do nothing) already covers the other.
//
// If the human later wants leaders to reject too, the change is one extra
// branch in UserPolicy plus the same LeaderRecruitScope::mayApprove() guard
// here — flagged in the TASK-115 completion report rather than pre-built.
class AgentApprovalService
{
    public function __construct(
        private NotificationService $notifier,
        private LeaderRecruitScope $leaderRecruitScope,
    ) {}

    public function approve(User $user, User $actor): User
    {
        $source = $this->resolveSource($actor);

        // Defense in depth (Section 6: "Policy + Gate ... on every
        // endpoint", and Section 7: the Service owns the rule). The
        // Controller has already run UserPolicy::approveRegistration, so in
        // the HTTP path this can never fire — it exists so that any FUTURE
        // caller (a console command, a queued job, a second controller)
        // cannot obtain a leader approval without satisfying the same scope.
        // AuthorizationException renders as 403, matching the Policy's answer.
        if ($source === ApprovalSource::TeamLeader && ! $this->leaderRecruitScope->mayApprove($actor, $user)) {
            throw new AuthorizationException('คุณไม่มีสิทธิ์อนุมัติผู้สมัครรายนี้');
        }

        $this->assertPending($user);

        $oldStatus = $user->agent_approval_status?->value;

        // forceFill()->save() rather than update(): the three attribution
        // columns are deliberately NOT in User::$fillable (Section 6, mass
        // assignment) so that no Form Request can ever set them. This is the
        // one place allowed to write them, and it writes all five columns in
        // a single save.
        $user->forceFill([
            'agent_approval_status' => AgentApprovalStatus::Approved,
            'approval_rejection_reason' => null,
            // ADR-025 §7 mitigation — WHO let this person in, and by which
            // route. TASK-117's Admin queue names the approver from these.
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'approval_source' => $source,
        ])->save();

        // TASK-041 (4.1) — Section 6: "record every action that affects
        // ... certification, or permissions" — an approval is exactly
        // that (it's what unlocks BR-1 selling rights). Shape copied
        // exactly from UserService::moveToCompany()'s AuditLog::create() call.
        //
        // TASK-115: the ACTION STRING differs by source, so "show me every
        // agent a team leader admitted without an admin looking" is a single
        // WHERE on the existing audit-log viewer rather than a JSON search.
        // approval_source is ALSO in new_values, so the row is
        // self-describing if the action vocabulary is ever refactored.
        AuditLog::create([
            'company_id' => $user->company_id,
            'actor_user_id' => $actor->id,
            'action' => $source === ApprovalSource::TeamLeader
                ? 'agent_approval.approved_by_leader'
                : 'agent_approval.approved',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => [
                'agent_approval_status' => $oldStatus,
                'approved_by_user_id' => null,
                'approval_source' => null,
            ],
            'new_values' => [
                'agent_approval_status' => AgentApprovalStatus::Approved->value,
                'approved_by_user_id' => $actor->id,
                'approval_source' => $source->value,
            ],
            'ip_address' => request()?->ip(),
        ]);

        // TASK-053 Phase 2b — welcome the newly-approved agent; this is
        // the first thing waiting on their home bell when BR-1 selling
        // rights unlock.
        $this->notifier->notify(
            $user,
            NotificationType::ApprovalStatus,
            'บัญชีของคุณได้รับการอนุมัติแล้ว',
            'คุณสามารถเริ่มเรียน Academy และเข้าถึงฟีเจอร์การขายได้แล้ว',
            '/',
        );

        return $user->fresh();
    }

    /**
     * ADMIN ONLY — see the class docblock for why a team leader may not
     * reject. UserPolicy::approveRegistration deliberately does not cover
     * this action; AgentApprovalController::reject() still authorizes
     * against UserPolicy::update().
     */
    public function reject(User $user, ?string $reason, User $actor): User
    {
        $this->assertAdminActor($actor, 'ปฏิเสธ');

        $this->assertPending($user);

        $oldStatus = $user->agent_approval_status?->value;

        $user->forceFill([
            'agent_approval_status' => AgentApprovalStatus::Rejected,
            'approval_rejection_reason' => $reason,
            // A rejected row has no effective approval to attribute. Nulled
            // rather than left stale; the history lives in the audit log.
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approval_source' => null,
        ])->save();

        // TASK-041 (4.1) — same Section 6 coverage as approve() above.
        AuditLog::create([
            'company_id' => $user->company_id,
            'actor_user_id' => $actor->id,
            'action' => 'agent_approval.rejected',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => ['agent_approval_status' => $oldStatus],
            'new_values' => ['agent_approval_status' => AgentApprovalStatus::Rejected->value],
            'ip_address' => request()?->ip(),
        ]);

        // TASK-053 Phase 2b — notify the applicant of the decision (with
        // the reason, if one was given).
        $this->notifier->notify(
            $user,
            NotificationType::ApprovalStatus,
            'การสมัครไม่ได้รับการอนุมัติ',
            $reason !== null && $reason !== '' ? "เหตุผล: {$reason}" : null,
            null,
        );

        return $user->fresh();
    }

    /**
     * TASK-115 / ADR-025 §7 — "Company Admins keep the full approval queue
     * and can reverse anything a leader did."
     *
     * Reverses an APPROVED registration back to Rejected. Admin-only, and
     * deliberately a separate verb from reject(): reject() answers a pending
     * application, this answers an admission that already happened, and the
     * two need different preconditions (Pending vs Approved) and different
     * audit actions so an auditor can tell them apart.
     *
     * Rejected, not Pending, is the target state: Pending would leave the
     * person in the queue for the same leader to approve again, which is
     * exactly the loop ADR-025 §7 forbids ("a leader can never
     * reject-then-reassign" — nor approve-then-be-overruled-then-approve).
     * Per ADR-005 decision 7 they may still submit a fresh registration.
     */
    public function revoke(User $user, ?string $reason, User $actor): User
    {
        $this->assertAdminActor($actor, 'เพิกถอนการอนุมัติของ');

        if ($user->agent_approval_status !== AgentApprovalStatus::Approved) {
            throw ValidationException::withMessages([
                'agent_approval_status' => 'ผู้ใช้นี้ไม่ได้อยู่ในสถานะอนุมัติแล้ว จึงเพิกถอนไม่ได้',
            ]);
        }

        $previousApproverId = $user->approved_by_user_id;
        $previousSource = $user->approval_source?->value;

        $user->forceFill([
            'agent_approval_status' => AgentApprovalStatus::Rejected,
            'approval_rejection_reason' => $reason,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'approval_source' => null,
        ])->save();

        // The old_values here are the whole point of this audit row: they
        // record WHOSE approval was overturned, which is how "an admin
        // reversed a leader's decision" becomes visible after the fact.
        AuditLog::create([
            'company_id' => $user->company_id,
            'actor_user_id' => $actor->id,
            'action' => 'agent_approval.revoked',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => [
                'agent_approval_status' => AgentApprovalStatus::Approved->value,
                'approved_by_user_id' => $previousApproverId,
                'approval_source' => $previousSource,
            ],
            'new_values' => [
                'agent_approval_status' => AgentApprovalStatus::Rejected->value,
                'approved_by_user_id' => null,
                'approval_source' => null,
            ],
            'ip_address' => request()?->ip(),
        ]);

        $this->notifier->notify(
            $user,
            NotificationType::ApprovalStatus,
            'สถานะบัญชีของคุณถูกเปลี่ยนแปลง',
            $reason !== null && $reason !== '' ? "เหตุผล: {$reason}" : null,
            null,
        );

        return $user->fresh();
    }

    /**
     * TASK-116 point 3 needs the leader's own pending recruits. The rule
     * lives in LeaderRecruitScope so the list and the approve action can
     * never disagree — see that class.
     *
     * @return Builder<User>
     */
    public function pendingRecruitsFor(User $leader): Builder
    {
        return $this->leaderRecruitScope->pendingRecruitsQuery($leader);
    }

    /**
     * An admin acting is an admin approval; anything else that got past the
     * Policy is, by construction, the leader carve-out. Derived from role
     * rather than passed in by the Controller so a caller cannot mislabel
     * its own action.
     */
    private function resolveSource(User $actor): ApprovalSource
    {
        return $actor->isSuperAdmin() || $actor->isCompanyAdmin()
            ? ApprovalSource::Admin
            : ApprovalSource::TeamLeader;
    }

    private function assertAdminActor(User $actor, string $verb): void
    {
        if (! $actor->isSuperAdmin() && ! $actor->isCompanyAdmin()) {
            throw new AuthorizationException("เฉพาะผู้ดูแลบริษัทเท่านั้นที่{$verb}ผู้สมัครได้");
        }
    }

    private function assertPending(User $user): void
    {
        if ($user->agent_approval_status !== AgentApprovalStatus::Pending) {
            throw ValidationException::withMessages([
                'agent_approval_status' => 'ผู้ใช้นี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว (อาจถูกดำเนินการไปแล้วโดยผู้ดูแลคนอื่น)',
            ]);
        }
    }
}
