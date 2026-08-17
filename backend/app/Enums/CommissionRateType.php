<?php

namespace App\Enums;

// BR-2/BR-3: commission_rules.rate_type. "percentage" stores rate_value as
// basis points (500 = 5.00%) — never a float, per BR-3's anti-float spirit
// even though BR-3 is written specifically about money columns. See the
// rate_value column comment on the commission_rules migration.
enum CommissionRateType: string
{
    case Percentage = 'percentage';
    case FixedSatang = 'fixed_satang';
}
