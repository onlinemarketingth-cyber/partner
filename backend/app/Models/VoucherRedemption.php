<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-033 (TASK-189) §2.2 — an immutable audit-trail row: who redeemed a
 * voucher, when, at which free-text "branch" (never a `branches` FK, see
 * ADR-033 §2.1). `created_at` only — no `updated_at`, because a redemption
 * is never edited after creation (same immutability spirit as BR-4's
 * commission ledger, though this carries no money).
 *
 * company_id DOES carry TenantScope here (§5 rule 1), even though the
 * OrderVoucher it redeems does not have one of its own — this row is the
 * tenant-scoped fact.
 */
class VoucherRedemption extends Model
{
    use HasFactory;

    /** created_at only — see class docblock. */
    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'order_voucher_id',
        'company_id',
        'redeemed_by_user_id',
        'redeemed_at_branch',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<OrderVoucher, $this> */
    public function orderVoucher(): BelongsTo
    {
        return $this->belongsTo(OrderVoucher::class);
    }

    /** @return BelongsTo<User, $this> The staff member who redeemed it. */
    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }
}
