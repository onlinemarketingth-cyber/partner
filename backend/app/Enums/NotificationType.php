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

    public function label(): string
    {
        return match ($this) {
            self::Announcement => 'ข่าวสาร',
            self::FollowUpDue => 'ติดตามลูกค้า',
            self::ExamPassed => 'สอบผ่าน',
            self::ExamFailed => 'สอบไม่ผ่าน',
            self::CommissionPaid => 'ค่าคอมมิชชั่น',
            self::ApprovalStatus => 'สถานะอนุมัติ',
            self::Reward => 'รางวัล',
            self::System => 'ระบบ',
            self::OrderPaymentConfirmed => 'ยืนยันการชำระเงิน',
            self::OrderPaymentFailed => 'ชำระเงินไม่สำเร็จ',
            self::OrderRefundReported => 'แจ้งการคืนเงิน',
        };
    }
}
