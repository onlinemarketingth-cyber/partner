<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-042 §3 — the audit/traceability record for every qualifying
 * promotion-bonus event (a targeted referral reaching Complete
 * Payment), created immediately regardless of the parent
 * AgentPromotion::payout_timing. commission_ledger_id/paid_at start
 * null and are the only fields ever mutated after creation — set once,
 * by PromotionBonusService::payCredit(), either inline (immediate) or
 * later by the PayDueAgentPromotionCredits scheduled command
 * (monthly_batch). See the creating migration's docblock for why there
 * is no repeat-limit/cap and no updated_at column.
 */
class AgentPromotionCredit extends Model
{
    use HasFactory;

    /** No updated_at column exists — see migration docblock. */
    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'agent_promotion_id',
        'referral_id',
        'user_id',
        'bonus_amount_satang',
        'commission_ledger_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'bonus_amount_satang' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<AgentPromotion, $this> */
    public function agentPromotion(): BelongsTo
    {
        return $this->belongsTo(AgentPromotion::class);
    }

    /** @return BelongsTo<Referral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /** @return BelongsTo<User, $this> The agent who earns/earned this bonus. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CommissionLedger, $this> Null until this credit has actually been paid out. */
    public function commissionLedger(): BelongsTo
    {
        return $this->belongsTo(CommissionLedger::class);
    }
}
