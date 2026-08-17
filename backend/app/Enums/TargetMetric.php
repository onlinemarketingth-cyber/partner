<?php

namespace App\Enums;

// TASK-053 / ADR-016 — what an agent_targets row measures. sales_satang is
// money (BR-3 integer satang); deals/clients are counts.
enum TargetMetric: string
{
    case SalesSatang = 'sales_satang';
    case Deals = 'deals';
    case Clients = 'clients';

    public function label(): string
    {
        return match ($this) {
            self::SalesSatang => 'ยอดขาย',
            self::Deals => 'จำนวนดีล',
            self::Clients => 'จำนวนลูกค้า',
        };
    }
}
