<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of ONE commission ledger row a given withdrawal request is
 * drawing on (2026-08-27). See the table's migration for why the allocation
 * is recorded here instead of as a flag on the ledger.
 *
 * NO TenantScope: this row has no company_id of its own on purpose. It is
 * reachable only through a CommissionWithdrawalRequest, which is scoped, and
 * duplicating the tenant key here would create a second place for it to be
 * wrong.
 */
class CommissionWithdrawalItem extends Model
{
    protected $fillable = [
        'commission_withdrawal_request_id',
        'commission_ledger_id',
        'allocated_satang',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allocated_satang' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CommissionWithdrawalRequest::class, 'commission_withdrawal_request_id');
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(CommissionLedger::class, 'commission_ledger_id');
    }
}
