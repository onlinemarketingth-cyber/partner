<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\SupplierGpMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 2026-09-16 — one sale of a supplier's product, and what it left them owed.
 *
 * The supplier-side twin of CommissionLedger, and deliberately NOT a row in
 * it (see the migration for the two reasons). Immutable in the same way: only
 * `payment_status` and `released_at` ever change after insert, and both are
 * state transitions rather than corrections.
 *
 * ── NO TenantScope, ON PURPOSE ──
 *
 * A row here belongs to two companies — the one that sold and the one that
 * gets paid. A global scope would have to choose one, and would then be wrong
 * for every query written from the other side. So there is none, and every
 * query in the codebase says which company it means. That is the same
 * reasoning OrderVoucher uses, and it carries the same obligation: a missing
 * filter here does not show too little, it shows another supplier's sales.
 */
class SupplierSettlementLedger extends Model
{
    use HasFactory;

    protected $table = 'supplier_settlement_ledger';

    protected $fillable = [
        'supplier_id',
        'company_id',
        'order_id',
        'product_id',
        'sale_price_satang_at_time',
        'commission_satang_at_time',
        'gp_mode_at_time',
        'gp_value_at_time',
        'gp_satang_at_time',
        'wht_rate_at_time',
        'amount_satang',
        'released_at',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'sale_price_satang_at_time' => 'integer',
            'commission_satang_at_time' => 'integer',
            'gp_mode_at_time' => SupplierGpMode::class,
            'gp_value_at_time' => 'integer',
            'gp_satang_at_time' => 'integer',
            'wht_rate_at_time' => 'integer',
            // Signed — a shortfall is carried by the supplier (owner's ruling).
            'amount_satang' => 'integer',
            'released_at' => 'datetime',
            'payment_status' => PaymentStatus::class,
        ];
    }

    /**
     * Who we owe. A Supplier, not a Company — see the Supplier model for why
     * those are different things.
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** The company that SOLD it — not this row's owner. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Money the supplier can actually ask for.
     *
     * BOTH conditions, and the second is the one people forget: unreleased
     * rows are real debts but not yet payable, and counting them would let a
     * payout go out before the parcel did.
     *
     * Note there is no `amount_satang > 0` filter here, deliberately. Negative
     * rows belong in the balance — that is how a shortfall nets off against
     * the supplier's other sales, which is the whole of the owner's ruling
     * that the supplier carries it. Whether the NET is positive enough to pay
     * is a question for the payout service, not for this scope.
     */
    public function scopePayable($query)
    {
        return $query
            ->where('payment_status', PaymentStatus::Pending->value)
            ->whereNotNull('released_at');
    }
}
