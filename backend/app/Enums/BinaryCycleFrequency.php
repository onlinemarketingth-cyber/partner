<?php

namespace App\Enums;

// ADR-006 Round 4: commission_binary_settings.cycle_frequency — how
// often a company's binary matched-volume cycle runs and pays out.
// This is a fixed vocabulary of admin-selectable options (like
// CommissionRateType's percentage/fixed_satang), not a business VALUE
// under BR-7 — the actual rate/cap/carry-over numbers stay in
// commission_binary_settings' own columns, never hardcoded here.
enum BinaryCycleFrequency: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';
}
