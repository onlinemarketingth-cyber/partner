<?php

namespace App\Services\Commission;

use App\Enums\CommissionBasis;
use App\Models\ProductPricePromotion;

/**
 * 2026-09-12 — the four facts that explain one commission_ledger row's
 * amount, carried together so they cannot be carried apart.
 *
 * ── WHY A VALUE OBJECT AND NOT FOUR ARGUMENTS ──
 *
 * PV introduced a second money-shaped integer into CommissionService
 * (what the customer paid, and what the rate was applied to), and the
 * two are interchangeable at every call site: same type, same units,
 * same order of magnitude, and on a price-basis company the same VALUE —
 * so a transposition would pass every existing test and only surface as
 * a wrong payout at a PV company. The compiler cannot catch
 * `f($base, $price)` where `f($price, $base)` was meant. It can catch a
 * missing object.
 *
 * They also must never be written to a row one without the other: BR-4
 * makes a commission_ledger row uneditable, so a row that records the
 * base but not which basis produced it, or the basis but not the figure,
 * is permanently unexplainable. ledgerColumns() is what makes "all four
 * or none" the only shape a caller can express.
 *
 * Readonly for the obvious reason: this is the snapshot of a moment, and
 * a snapshot that can be amended after the fact is not one.
 */
final class SaleValueSnapshot
{
    public function __construct(
        /** What the customer is charged, promotion already applied. */
        public readonly int $salePriceSatang,
        /** Which base was in force — recorded even when it made no difference. */
        public readonly CommissionBasis $basis,
        /** The figure a rate is actually applied to (CommissionBasisResolver). */
        public readonly int $baseSatang,
        /** The promotion behind $salePriceSatang, if any. */
        public readonly ?ProductPricePromotion $appliedPromotion,
    ) {}

    /**
     * The columns every commission_ledger row written for this sale must
     * carry, spread into the create() array.
     *
     * The basis is recorded even when it is Price and even when it
     * changed nothing. "This row was written by a price-basis company"
     * and "this row predates PV" are different facts, and only one of
     * them can be inferred from a NULL.
     *
     * @return array<string, mixed>
     */
    public function ledgerColumns(): array
    {
        return [
            'sale_price_satang_at_time' => $this->salePriceSatang,
            'applied_price_promotion_id_at_time' => $this->appliedPromotion?->id,
            'commission_basis_at_time' => $this->basis,
            'commission_base_satang_at_time' => $this->baseSatang,
        ];
    }
}
