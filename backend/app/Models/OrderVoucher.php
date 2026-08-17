<?php

namespace App\Models;

use App\Enums\VoucherStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-033 (TASK-189) §2.2 — post-payment service-access voucher, one row
 * per PAID order (1:1). Deliberately has NO company_id column of its own:
 * it hangs off `order`, and every tenant check against it goes through
 * $voucher->order->company_id (see VoucherRedemptionService), never a
 * TenantScope on this model.
 *
 * usage_quota/expires_at are snapshots taken at issuance
 * (OrderVoucherService::issueFor()) — never re-read from `product` live.
 */
class OrderVoucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'code',
        'usage_quota',
        'used_count',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'usage_quota' => 'integer',
            'used_count' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<VoucherRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    /**
     * ADR-033 §2.2/B3 — COMPUTED, not stored, so there is no second
     * predicate that can drift from usage_quota/used_count/expires_at.
     * Quota is checked before expiry: an exhausted voucher should be named
     * as exhausted even if it has also since passed its expiry date.
     */
    public function status(): VoucherStatus
    {
        if ($this->usage_quota !== null && $this->used_count >= $this->usage_quota) {
            return VoucherStatus::Exhausted;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return VoucherStatus::Expired;
        }

        return VoucherStatus::Active;
    }

    /** Null = unlimited (no quota set). */
    public function quotaRemaining(): ?int
    {
        return $this->usage_quota === null ? null : max(0, $this->usage_quota - $this->used_count);
    }
}
