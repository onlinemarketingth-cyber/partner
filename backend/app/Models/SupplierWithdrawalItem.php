<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 2026-09-16 — how much of ONE settlement row a given payout is drawing on.
 *
 * The supplier-side twin of CommissionWithdrawalItem, including the part that
 * matters most: the allocation lives here rather than as a flag on the ledger
 * row, because a row can be drawn on across more than one payout and a
 * boolean cannot say how much of it this one took.
 *
 * NO TenantScope and no company_id of its own: reachable only through a
 * request, which is access-checked. Duplicating the key would create a second
 * place for it to be wrong.
 *
 * `allocated_satang` is SIGNED here where the commission twin's is not — a
 * shortfall row (commission + GP exceeded the sale price, and the supplier
 * carries it) is allocated into the payout alongside the positive rows so that
 * it nets off AND gets closed. Excluded, it would sit in the balance forever,
 * silently docking every future payout by the same amount.
 */
class SupplierWithdrawalItem extends Model
{
    protected $fillable = [
        'supplier_withdrawal_request_id',
        'supplier_settlement_ledger_id',
        'allocated_satang',
    ];

    protected function casts(): array
    {
        return [
            'allocated_satang' => 'integer',
        ];
    }

    /** @return BelongsTo<SupplierWithdrawalRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SupplierWithdrawalRequest::class, 'supplier_withdrawal_request_id');
    }

    /** @return BelongsTo<SupplierSettlementLedger, $this> */
    public function ledger(): BelongsTo
    {
        return $this->belongsTo(SupplierSettlementLedger::class, 'supplier_settlement_ledger_id');
    }
}
