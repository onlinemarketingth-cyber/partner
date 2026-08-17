<?php

namespace App\Models;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionRateType;
use App\Enums\PaymentStatus;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-4: immutable once created — never edit rate_applied/amount_satang
 * after creation. payment_status/paid_at are the one allowed mutable
 * field. Historical reports always read from here, never recompute live.
 */
class CommissionLedger extends Model
{
    use HasFactory;

    // Migration creates a singular "commission_ledger" table (see that
    // migration's own comment) — Eloquent's default pluralization
    // would otherwise look for "commission_ledgers" and fail with
    // "no such table". Same bug, same fix as XpLedger.
    protected $table = 'commission_ledger';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'agent_id',
        'referral_id',
        'cert_tier_id_at_time',
        'product_id',
        // TASK-047 — immutable snapshot of the price basis (post-promotion
        // if one was active) used for THIS row's calculation, see the
        // migration's own comment for why these live here rather than on
        // referrals.
        'sale_price_satang_at_time',
        'applied_price_promotion_id_at_time',
        'rate_type_applied',
        'rate_applied',
        'amount_satang',
        'payment_status',
        'paid_at',
        // TASK-024/025 + ADR-006 Round 4 (Binary) — referral_id,
        // cert_tier_id_at_time, product_id are nullable as of
        // 2026_07_14_130000 specifically so a binary_match row (tied to
        // a matching cycle, not one referral/product/cert tier) can
        // omit them; every other earned_via still always sets them
        // (enforced in CommissionService, not the DB — see that
        // migration's comment).
        'earned_via',
        'override_source_agent_id',
        'source_binary_cycle_id',
        // TASK-042 §3 — null unless earned_via = PromotionBonus (same
        // marker pattern as source_binary_cycle_id above).
        'source_agent_promotion_id',
    ];

    protected function casts(): array
    {
        return [
            'rate_type_applied' => CommissionRateType::class,
            'rate_applied' => 'integer',
            'amount_satang' => 'integer',
            'sale_price_satang_at_time' => 'integer',
            'payment_status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'earned_via' => CommissionEarnedVia::class,
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<Referral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /** @return BelongsTo<CertTier, $this> */
    public function certTierAtTime(): BelongsTo
    {
        return $this->belongsTo(CertTier::class, 'cert_tier_id_at_time');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> TASK-025 — the downline agent this override was earned from (null unless earned_via = override). */
    public function overrideSourceAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_source_agent_id');
    }

    /** @return BelongsTo<BinaryMatchingCycle, $this> ADR-006 Round 4 — the cycle this payout came from (null unless earned_via = binary_match). */
    public function sourceBinaryCycle(): BelongsTo
    {
        return $this->belongsTo(BinaryMatchingCycle::class, 'source_binary_cycle_id');
    }

    /** @return BelongsTo<AgentPromotion, $this> TASK-042 §3 — the campaign this bonus came from (null unless earned_via = promotion_bonus). */
    public function sourceAgentPromotion(): BelongsTo
    {
        return $this->belongsTo(AgentPromotion::class, 'source_agent_promotion_id');
    }

    /** @return BelongsTo<ProductPricePromotion, $this> TASK-047 — the price promotion (if any) active at the moment this row was calculated. Null when no promotion was active, or for earned_via types this task didn't extend (BinaryMatch/MatrixOverride/StairstepOverride/GenerationOverride/PromotionBonus). */
    public function appliedPricePromotionAtTime(): BelongsTo
    {
        return $this->belongsTo(ProductPricePromotion::class, 'applied_price_promotion_id_at_time');
    }
}
