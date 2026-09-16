<?php

namespace App\Enums;

// TASK-053 / ADR-016 — kinds of notification the platform can push to an
// agent. Kept as a small fixed vocabulary (not free strings) so the
// frontend can pick an icon/style per type. Extend here as more events
// are wired in Phase 2.
enum NotificationType: string
{
    case Announcement = 'announcement';       // a new announcement targeting this agent
    case FollowUpDue = 'follow_up_due';       // a client follow-up is due/overdue
    case ExamPassed = 'exam_passed';
    case ExamFailed = 'exam_failed';
    case CommissionPaid = 'commission_paid';
    case ApprovalStatus = 'approval_status';  // agent approval approved/rejected
    case Reward = 'reward';                   // reward redemption / promotion bonus
    case System = 'system';                   // generic/system message
    // TASK-190 §4.1 — fired from OrderService::confirmPayment(), same
    // guard (! $alreadyClosed) as the voucher issuance it sits next to
    // (ADR-033/TASK-189 B1). Tells the referral's agent their customer's
    // payment was confirmed — separate from CommissionPaid, which fires
    // later, only once an Admin marks the commission ledger entry paid.
    case OrderPaymentConfirmed = 'order_payment_confirmed';
    /*
     * 2026-09-03 — the other half of that story.
     *
     * A gateway reports failures and timeouts as readily as successes, and
     * until now only the success reached the agent. The person who can DO
     * something about a customer whose card was declined is the agent who
     * sold to them, and they were the one person the system never told.
     */
    case OrderPaymentFailed = 'order_payment_failed';
    /*
     * The gateway says the sale was refunded. The agent's commission has NOT
     * been reversed — that is a human decision (BR-4, CommissionReversalService)
     * — but it may be about to be, and finding out from a balance that
     * changed without explanation is the worst way to learn it.
     */
    case OrderRefundReported = 'order_refund_reported';
    /*
     * 2026-09-15 — THE PAYOUT ROUTE THAT TOLD NOBODY ANYTHING.
     *
     * Owner: "ตัวแทนขอเบิกผ่านหน้า frontend แล้วแจ้งให้ admin ทราบ อันนี้ไม่มี
     * การแจ้งเตือนเลย". The withdrawal flow had four state changes and zero
     * notifications, while the admin's one-click payout — where nobody was
     * waiting — sent an email. Exactly backwards.
     *
     * TWO types, not one, because they point in opposite directions and only
     * one of them can be switched off without stranding somebody:
     *
     *   Requested — to the COMPANY ADMINS. An agent is now blocked on a human
     *   opening a queue, and nothing in the system said so.
     *
     *   Decided — to the AGENT: approved (money is coming, it has not moved
     *   yet) or rejected (with the reason the admin was required to type, and
     *   which the agent otherwise had to go looking for).
     *
     * The transfer itself is deliberately NOT here: it is CommissionPaid,
     * the existing type, because "your commission was paid" is one event and
     * should not arrive as two different kinds of message depending on which
     * screen the admin used.
     */
    case CommissionWithdrawalRequested = 'commission_withdrawal_requested';
    case CommissionWithdrawalDecided = 'commission_withdrawal_decided';

    public function label(): string
    {
        return match ($this) {
            self::Announcement => 'ข่าวสาร',
            self::FollowUpDue => 'ติดตามลูกค้า',
            self::ExamPassed => 'สอบผ่าน',
            self::ExamFailed => 'สอบไม่ผ่าน',
            self::CommissionPaid => 'ค่าแนะนำ',
            self::ApprovalStatus => 'สถานะอนุมัติ',
            self::Reward => 'รางวัล',
            self::System => 'ระบบ',
            self::OrderPaymentConfirmed => 'ยืนยันการชำระเงิน',
            self::OrderPaymentFailed => 'ชำระเงินไม่สำเร็จ',
            self::OrderRefundReported => 'แจ้งการคืนเงิน',
            self::CommissionWithdrawalRequested => 'คำขอเบิกค่าแนะนำ',
            self::CommissionWithdrawalDecided => 'ผลคำขอเบิกค่าแนะนำ',
        };
    }
}
