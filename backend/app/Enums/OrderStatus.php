<?php

namespace App\Enums;

// ADR-017 (TASK-054) — an order's payment lifecycle. Kept as a small
// fixed vocabulary (not free strings) so the UI can pick a style/label
// per state. Lifecycle: pending -> awaiting_verification -> paid; either
// of the first two may be cancelled. `paid` is terminal and is the state
// that triggers the referral advance to Complete Payment (BR-4).
enum OrderStatus: string
{
    case Pending = 'pending';                          // created, customer hasn't paid/uploaded a slip yet
    case AwaitingVerification = 'awaiting_verification'; // slip uploaded, waiting for agent/admin to verify
    case Paid = 'paid';                                // verified — terminal, triggered the sale close
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'รอชำระเงิน',
            self::AwaitingVerification => 'รอตรวจสอบสลิป',
            self::Paid => 'ชำระเงินแล้ว',
            self::Cancelled => 'ยกเลิก',
        };
    }
}
