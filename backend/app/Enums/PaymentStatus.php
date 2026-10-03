<?php

namespace App\Enums;

// BR-4: commission_ledger.payment_status — the one field allowed to
// mutate on an otherwise-immutable ledger row.
//
// Also used by supplier_settlement_ledger.payment_status, which only ever
// holds Pending / Paid.
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';

    /**
     * MOB-12 follow-up (owner decision 2026-10-03) — the agent gave this
     * commission up when they deleted their own account
     * (AccountDeletionService, "ยินยอมไม่รับค่าคอม").
     *
     * Terminal, and neither owed nor paid: every payable / owed / available
     * query in the codebase filters on `= pending` (and every "paid" figure on
     * `= paid`), so a forfeited row drops out of payout queues, withdrawal
     * balances, owed totals and paid totals at once, while the row itself —
     * the record that the commission was earned — stays exactly as written.
     * Only commission_ledger rows are ever forfeited; supplier rows are not.
     *
     * Before this case existed the ledger deliberately had two statuses (see
     * the 2026_09_29_090000 migration's note); every `payment_status` reader
     * was re-checked when it was added — the list is in the MOB-12 report.
     */
    case Forfeited = 'forfeited';

    /** Thai label, so the two frontends and any export say the same word. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'รอจ่าย',
            self::Paid => 'จ่ายแล้ว',
            self::Forfeited => 'สละสิทธิ์',
        };
    }
}
