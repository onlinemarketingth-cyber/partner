<?php

namespace App\Enums;

/**
 * Lifecycle of an agent's commission withdrawal request (2026-08-27).
 *
 * The two "open" states — PendingReview and Approved — are the ones that
 * hold money aside: their allocations count against the agent's available
 * balance so the same commission cannot be requested twice while a decision
 * or a transfer is outstanding. Rejected and Cancelled release it again;
 * Transferred consumes it for good.
 */
enum WithdrawalStatus: string
{
    /** Agent has asked; nobody has decided yet. */
    case PendingReview = 'pending_review';

    /**
     * An admin agreed the request is legitimate. The money has NOT moved —
     * that is Transferred. Kept separate on purpose: approving is a
     * decision, transferring is an event, and a transfer can fail after an
     * approval that was perfectly correct.
     */
    case Approved = 'approved';

    case Rejected = 'rejected';

    /** Withdrawn by the agent themselves, before a decision was made. */
    case Cancelled = 'cancelled';

    /** Money has actually left the company's account. Terminal. */
    case Transferred = 'transferred';

    /**
     * The states that reserve commission against the agent's balance.
     *
     * ONE definition, used by the balance maths, the "can I cancel this"
     * check and the admin queue alike — a second copy of this list written
     * out at a call site is how an amount ends up requestable twice.
     *
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::PendingReview, self::Approved];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'รอตรวจสอบ',
            self::Approved => 'อนุมัติแล้ว รอโอน',
            self::Rejected => 'ไม่อนุมัติ',
            self::Cancelled => 'ยกเลิกโดยตัวแทน',
            self::Transferred => 'โอนเงินแล้ว',
        };
    }
}
