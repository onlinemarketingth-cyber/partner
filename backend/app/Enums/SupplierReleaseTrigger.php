<?php

namespace App\Enums;

/**
 * 2026-09-16 — WHEN A SUPPLIER'S MONEY BECOMES WITHDRAWABLE.
 *
 * Owner: "ตามแต่ละดีล Setup ได้" — so this is a term of the deal, per
 * supplier, not one rule for the platform.
 *
 * ── WHAT THIS DOES *NOT* CONTROL ──
 *
 * It does not decide whether the ledger row exists. The row is written the
 * moment the order is paid, always, whatever this says, because the sale has
 * happened and accounting has to be able to see what we owe from that instant.
 * This enum sets `released_at` and nothing else.
 *
 * Writing the row late would mean a payables figure that is only correct once
 * every outstanding parcel has been delivered — a number nobody could
 * reconcile on any given day.
 *
 * ── WHO CARRIES THE RISK ──
 *
 * The three cases are a risk dial, and it points at us:
 *
 *   OnPayment   we may pay the supplier before they have delivered anything.
 *               If the customer then refunds, we are out of pocket and have
 *               to ask for it back.
 *   OnRedeemed  the customer has turned up and taken the service.
 *   OnDelivered the recipient has the parcel — or the auto-receive window
 *               after shipping passed with nobody confirming (ADR-048).
 *
 * The last two are the safe ones; the first exists because some suppliers
 * will not agree to anything else.
 */
enum SupplierReleaseTrigger: string
{
    /** The customer's payment cleared. Earliest, and the riskiest for us. */
    case OnPayment = 'on_payment';

    /** The voucher was redeemed — the service was actually taken. */
    case OnRedeemed = 'on_redeemed';

    /**
     * The recipient confirmed the parcel arrived — or nobody did within the
     * platform's auto-receive window after the supplier shipped it (ADR-048).
     * The stored value keeps its old name; what it waits for changed.
     */
    case OnDelivered = 'on_delivered';

    public function label(): string
    {
        return match ($this) {
            self::OnPayment => 'เมื่อลูกค้าชำระเงิน',
            self::OnRedeemed => 'เมื่อตัดสิทธิ์บัตรกำนัลแล้ว',
            self::OnDelivered => 'เมื่อผู้รับได้รับสินค้า',
        };
    }
}
