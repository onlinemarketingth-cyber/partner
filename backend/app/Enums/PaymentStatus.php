<?php

namespace App\Enums;

// BR-4: commission_ledger.payment_status — the one field allowed to
// mutate on an otherwise-immutable ledger row.
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
