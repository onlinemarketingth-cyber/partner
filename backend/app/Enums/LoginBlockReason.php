<?php

namespace App\Enums;

/**
 * TASK-115 / TASK-021 / ADR-025 §8 — why a login was refused AFTER the
 * password was verified as correct.
 *
 * These three are deliberately distinguishable FROM EACH OTHER: the person
 * on the other end already proved they own the account, and each state has a
 * different next action (verify an email / wait / re-apply). Collapsing them
 * into one message would not add security — it would only stop a legitimate
 * owner from knowing what to do (TASK-021's own framing, and the reason
 * ADR-005 decision 6 rejected a generic "invalid credentials" here).
 *
 * The boundary that DOES matter for enumeration is blocked-vs-nonexistent,
 * and it is protected elsewhere — see LoginGateService's docblock for the
 * full analysis. Nothing in this enum is ever emitted for an email the
 * caller does not already hold the correct password for.
 *
 * The `value` of each case IS the machine-readable `error_code` in the 403
 * body; TASK-116's LoginView branches on it. Treat these strings as a
 * published API contract — renaming one is a breaking frontend change.
 */
enum LoginBlockReason: string
{
    case EmailUnverified = 'email_unverified';
    case ApprovalPending = 'approval_pending';
    case ApprovalRejected = 'approval_rejected';
    /**
     * TASK-183 §3.4 — the tenant itself is closed (deactivated or
     * soft-deleted). Unlike the three above, this one is NOT about the
     * person's own registration state, applies to Company Admins as well as
     * Agents, and is emitted from TWO places: LoginGateService (at login) and
     * EnsureCompanyIsOperational (on every subsequent authenticated request),
     * which share this one string so the SPA has a single branch to handle.
     */
    case CompanyInactive = 'company_inactive';

    /**
     * User-facing Thai copy. Deliberately phrased so that the rejected case
     * does NOT read as permanent — ADR-005 decision 7: "a rejected person may
     * submit a fresh registration; no permanent lockout."
     */
    public function message(): string
    {
        return match ($this) {
            self::EmailUnverified => 'กรุณายืนยันอีเมลของคุณก่อนเข้าสู่ระบบ — กดปุ่มด้านล่างเพื่อส่งลิงก์ยืนยันอีกครั้ง',
            self::ApprovalPending => 'บัญชีของคุณอยู่ระหว่างรอการอนุมัติจากบริษัทของคุณ กรุณารอการติดต่อกลับ',
            self::ApprovalRejected => 'การสมัครครั้งก่อนของคุณยังไม่ได้รับการอนุมัติ คุณสามารถสมัครใหม่ได้อีกครั้ง',
            // TASK-183 §3.4 — names the COMPANY's status, never the
            // credentials, so the reader is sent to their company and not to a
            // password reset that would change nothing. It also says nothing
            // about which of "deactivated" or "deleted" applies: a
            // soft-deleted tenant and a switched-off one are the same fact to
            // the person locked out, and the distinction is internal.
            self::CompanyInactive => 'บริษัทของคุณถูกระงับการใช้งานอยู่ในขณะนี้ จึงไม่สามารถเข้าใช้งานระบบได้ กรุณาติดต่อผู้ดูแลระบบของบริษัทของคุณ',
        };
    }
}
