<?php

namespace App\Enums;

/**
 * TASK-115 / ADR-025 §7 — WHICH path approved a registration.
 *
 * ADR-025 §7 accepts a real residual risk knowingly: "a leader can now bring
 * people into the company without an admin ever looking." The mitigation it
 * names is visibility — "the Admin approval queue shows leader-approved
 * agents with their approver". This enum is the column that makes that
 * possible; without it the two paths are indistinguishable once the row has
 * flipped to `approved` and the audit log is the only trace.
 *
 * Stored, not derived from the approver's role at read time: a leader who is
 * later promoted to Company Admin must not retroactively relabel every
 * approval they made as a leader (TASK-117's queue would then under-report
 * exactly the thing it exists to surface).
 *
 * Fixed vocabulary like AgentApprovalStatus — not BR-7 config.
 */
enum ApprovalSource: string
{
    /** A Company Admin or Super Admin acted through the approval queue (ADR-005 decision 3). */
    case Admin = 'admin';

    /** A designated team leader approved their own recruit (ADR-025 §7). */
    case TeamLeader = 'team_leader';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'อนุมัติโดยผู้ดูแลบริษัท',
            self::TeamLeader => 'อนุมัติโดยหัวหน้าทีม',
        };
    }
}
