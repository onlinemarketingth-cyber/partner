<?php

namespace App\Models;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionRateType;
use App\Enums\PaymentStatus;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

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

    /**
     * The only columns BR-4 permits to change after a row exists.
     *
     * Payment STATUS is bookkeeping about the row; everything else IS the
     * row — who earned what, on which sale, at which rate, off which price.
     *
     * @var list<string>
     */
    public const MUTABLE_AFTER_CREATION = ['payment_status', 'paid_at', 'updated_at'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        /*
         * SECURITY AUDIT 2026-08-21 (V12) — BR-4 ENFORCED, NOT JUST STATED.
         *
         * "Immutable once created" was written at the top of this class,
         * written again in the migration, and enforced by exactly nothing:
         * no model event, no database trigger, no revoked grant. What
         * actually stopped a rewrite was that no route exists for one —
         * apiResource(...)->only(['index','show']) — which is a fact about
         * the HTTP surface, not about the invariant. Every artisan command,
         * queued job, future feature and tinker session had a free hand.
         *
         * That is a strange gap for the one rule the whole product rests
         * on. Commission is what this system pays people; a ledger you can
         * quietly edit is not a ledger, it is a cache with opinions. And an
         * edit would leave no trace: audit_logs is written by callers, so
         * the caller that skips the audit skips the record of skipping it.
         *
         * Enforced with an allowlist, not a blocklist, deliberately: a new
         * column added next year is protected by default, whereas a
         * blocklist protects it only if somebody remembers. `updated_at` is
         * in the list because Eloquent touches it alongside any permitted
         * change, not because it is meaningful.
         *
         * A LogicException rather than a silent `return false`: a silent
         * refusal makes the calling code believe the write succeeded, which
         * for money is the worst of the three possible behaviours. This is
         * a programming error and should read like one, in tests and in
         * production alike.
         */
        static::updating(function (self $entry): void {
            $forbidden = array_diff(array_keys($entry->getDirty()), self::MUTABLE_AFTER_CREATION);

            if ($forbidden !== []) {
                throw new LogicException(
                    'BR-4: commission_ledger rows are immutable. Refusing to change ['
                        .implode(', ', $forbidden).'] on entry '.$entry->getKey().'. '
                        .'To reverse a commission, write a REVERSING entry (see CommissionReversalService); never edit the original.',
                );
            }
        });

        /*
         * Deleting is refused outright, with no allowlist to argue about.
         *
         * The reason a reversal exists is that the original must survive:
         * "this sale was paid then refunded" and "this sale never happened"
         * are different facts, and only the first one is true. Deleting the
         * row destroys the audit trail of the very event the reversal is
         * accounting for.
         */
        static::deleting(function (self $entry): void {
            throw new LogicException(
                'BR-4: commission_ledger rows are never deleted (entry '.$entry->getKey().'). '
                    .'Write a reversing entry instead — the original is the record that it happened.',
            );
        });
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
        // SECURITY AUDIT 2026-08-21 (V15) — set only on a reversing entry.
        'reverses_commission_ledger_id',
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

    /** @return BelongsTo<CommissionLedger, $this> The entry this one reverses (null on an ordinary payout). */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_commission_ledger_id');
    }

    /**
     * The reversal written against THIS entry, if it has been refunded.
     *
     * hasOne, not hasMany, and the database agrees: a unique index on
     * reverses_commission_ledger_id makes a second reversal of the same
     * entry impossible rather than merely unusual.
     *
     * @return HasOne<CommissionLedger, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_commission_ledger_id');
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
