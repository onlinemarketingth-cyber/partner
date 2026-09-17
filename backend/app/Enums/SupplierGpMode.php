<?php

namespace App\Enums;

/**
 * 2026-09-16 — HOW MUCH OF A SUPPLIER'S SALE WE KEEP.
 *
 * Owner's formula: `ราคาขาย − ค่าคอม − GP` goes back to the supplier. This
 * enum is the third term, and the owner asked for all three shapes from day
 * one ("เต็มรูปแบบ").
 *
 * ── THE ORDER OF OPERATIONS IS NOT NEGOTIABLE ──
 *
 * Commission is subtracted FIRST, always, whichever mode this is. That is not
 * an implementation detail: `PercentOfNet` is defined as a percentage of what
 * is left after the members have been paid, so computing GP before commission
 * would silently turn it into PercentOfSale. See SupplierSettlementService,
 * which is the only place allowed to do this arithmetic.
 */
enum SupplierGpMode: string
{
    /**
     * A percentage of the price the customer paid, in basis points.
     *
     * The simplest to explain to a supplier and the most common in practice:
     * "we keep 30% of the sale". Note it is taken off the sale price, so on a
     * high-commission product our GP and the members' commission can together
     * exceed what the customer paid — which the owner has ruled is the
     * supplier's problem (see the ledger's negative-amount handling).
     */
    case PercentOfSale = 'percent_of_sale';

    /**
     * A percentage of what remains after commission, in basis points.
     *
     * "We split what is left after paying the sellers." Kinder to the supplier
     * on a heavily-commissioned product, and the one mode whose result MOVES
     * when a commission rate changes — a fact worth knowing before anybody
     * edits a rate and wonders why supplier statements shifted.
     */
    case PercentOfNet = 'percent_of_net';

    /** A flat amount of satang per sale, whatever the price. */
    case FixedPerUnit = 'fixed_per_unit';

    public function label(): string
    {
        return match ($this) {
            self::PercentOfSale => '% ของราคาขาย',
            self::PercentOfNet => '% ของยอดหลังหักค่าแนะนำ',
            self::FixedPerUnit => 'จำนวนเงินคงที่ต่อชิ้น',
        };
    }

    /** True when `supplier_gp_value` is basis points rather than satang. */
    public function isPercentage(): bool
    {
        return $this !== self::FixedPerUnit;
    }
}
