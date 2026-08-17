<?php

namespace App\Models;

use App\Enums\RedemptionStatus;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent-view IA item 1.5 — one redemption request per row. points_spent
 * is an immutable snapshot (see migration comment); status is mutated
 * exclusively through RewardRedemptionService::decide() (never directly
 * by a Controller) to keep the approve/reject/fulfill workflow + audit
 * fields consistent. shipping_recipient_name/shipping_phone/
 * shipping_address are captured once, at request time, by the agent
 * (TASK-042 §2) — required only when reward_item.reward_type is
 * physical (see StoreRewardRedemptionRequest). tracking_number is a
 * plain Admin-editable field, not part of the status state machine —
 * settable any time after status reaches Approved (see
 * RewardRedemptionService::updateTrackingNumber()).
 */
class RewardRedemption extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'reward_item_id',
        'points_spent',
        'status',
        'requested_at',
        'decided_by',
        'decided_at',
        'decision_note',
        'shipping_recipient_name',
        'shipping_phone',
        'shipping_address',
        'tracking_number',
    ];

    protected function casts(): array
    {
        return [
            'points_spent' => 'integer',
            'status' => RedemptionStatus::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> Requesting Agent. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RewardItem, $this> */
    public function rewardItem(): BelongsTo
    {
        return $this->belongsTo(RewardItem::class);
    }

    /** @return BelongsTo<User, $this> Admin who approved/rejected/fulfilled. */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
