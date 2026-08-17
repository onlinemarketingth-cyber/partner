<?php

namespace App\Services\Commission;

use App\Enums\CommissionRateType;

// BR-3: satang stays an integer end to end — the only place a float
// briefly exists is this one division, rounded away immediately.
//
// ADR-011/TASK-029 — pulled out of CommissionService (where it used to
// live as computeAmount()) into its own tiny class so BinaryCommissionService
// can reuse the exact same rounding rule without creating a circular
// constructor dependency: CommissionService needs to depend on
// BinaryCommissionService (to trigger volume crediting on a direct
// sale), so BinaryCommissionService can no longer depend back on
// CommissionService for this one calculation. CommissionService::
// computeAmount() now simply delegates here — every existing caller
// (direct-sale, override, renewal via DispatchDueRenewalCommissions,
// and every test referencing computeAmount()) keeps working unchanged.
class CommissionRateCalculator
{
    public static function compute(CommissionRateType $rateType, int $rateValue, int $baseSatang): int
    {
        return $rateType === CommissionRateType::Percentage
            ? (int) round($baseSatang * $rateValue / 10000) // rate_value is basis points (500 = 5.00%)
            : $rateValue; // fixed_satang — already in satang
    }
}
