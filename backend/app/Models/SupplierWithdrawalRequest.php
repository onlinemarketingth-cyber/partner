<?php

namespace App\Models;

use App\Enums\WithdrawalSource;
use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 2026-09-16 — one payment to a supplier.
 *
 * The supplier-side twin of CommissionWithdrawalRequest, sharing its two
 * enums (WithdrawalStatus, WithdrawalSource) because the process is
 * genuinely the same process. What differs is the payee — a company, not a
 * person — and tax, which this one withholds and that one has never had to.
 *
 * NO TenantScope, for the same reason SupplierSettlementLedger has none: the
 * supplier is not one of our tenants and the sales being settled may span
 * several of them. Access is decided per query.
 */
class SupplierWithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'source',
        'status',
        'gross_satang',
        'wht_rate_at_time',
        'wht_satang',
        'net_satang',
        'wht_certificate_no',
        'decided_by_user_id',
        'decided_at',
        'rejection_reason',
        'transferred_at',
        'transfer_reference',
        'bank_name',
        'bank_account_number',
        'bank_account_holder_name',
    ];

    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'source' => WithdrawalSource::class,
            'gross_satang' => 'integer',
            'wht_rate_at_time' => 'integer',
            'wht_satang' => 'integer',
            'net_satang' => 'integer',
            'decided_at' => 'datetime',
            'transferred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<SupplierWithdrawalItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierWithdrawalItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * Requests that are holding money aside.
     *
     * Same definition as the commission side (WithdrawalStatus::open()) and
     * the same purpose: an amount already spoken for by a request awaiting a
     * decision or a transfer must not be offered up a second time.
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', array_column(WithdrawalStatus::open(), 'value'));
    }
}
