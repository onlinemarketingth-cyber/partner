<?php

namespace App\Models;

use App\Enums\CertTierTargetMode;
use App\Enums\CommissionRateType;
use App\Enums\PromotionPayoutTiming;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Agent-view IA item 1.4 — targeted, time-boxed bonus campaign. Not a
 * commission_rules replacement (BR-2 rates stay permanent config); this
 * is an additive bonus layered on top, resolved/applied by
 * AgentPromotionService (never live-computed in a Controller/component
 * per Section 7).
 */
class AgentPromotion extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'product_id',
        'name',
        'description',
        'target_type',
        'target_cert_tier_id',
        'target_cert_tier_mode',
        'bonus_type',
        'bonus_value',
        'payout_timing',
        'status',
        'starts_at',
        'ends_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => PromotionTargetType::class,
            'target_cert_tier_mode' => CertTierTargetMode::class,
            'bonus_type' => CommissionRateType::class,
            'bonus_value' => 'integer',
            'payout_timing' => PromotionPayoutTiming::class,
            'status' => PromotionStatus::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<CertTier, $this> */
    public function targetCertTier(): BelongsTo
    {
        return $this->belongsTo(CertTier::class, 'target_cert_tier_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Only populated when target_type = PromotionTargetType::SpecificAgents. @return BelongsToMany<User, $this> */
    public function targetAgents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agent_promotion_agent');
    }

    /**
     * Whether this promotion's targeting rule (not status/date window —
     * callers check those separately, e.g. AgentPromotionController::index
     * filters is_currently_active before this) covers the given Agent.
     * Shared by AgentPromotionPolicy::view() and the Controller's index
     * narrowing so the "who does this apply to" logic lives in one place.
     */
    public function appliesToAgent(User $user): bool
    {
        return match ($this->target_type) {
            PromotionTargetType::AllAgents => true,
            // No single "current cert tier" column exists on User — BR-1
            // gate progress is derived from UserCertification rows (one
            // passed-row per tier reached, see that model's docblock).
            // Exact mode (default, preserves pre-TASK-042 behavior) = the
            // Agent has actually passed the exact target tier. AndAbove
            // mode (TASK-042 §4, BR-7 confirmed 2026-07-23) = the Agent
            // has passed ANY tier whose cert_tiers.sort_order is >= the
            // target tier's sort_order (sort_order is the established
            // ranking — see User::highestPassedCertTier() docblock:
            // Basic < Intermediate < High).
            PromotionTargetType::CertTier => $this->target_cert_tier_id !== null
                && $this->agentHoldsTargetedCertTier($user),
            PromotionTargetType::SpecificAgents => $this->relationLoaded('targetAgents')
                ? $this->targetAgents->contains('id', $user->id)
                : $this->targetAgents()->where('user_id', $user->id)->exists(),
        };
    }

    /**
     * TASK-042 §4 — exact vs "this tier and above" branching. Shares the
     * identical query shape with AnnouncementController::index()'s
     * targeting subquery so both features stay in lockstep.
     */
    private function agentHoldsTargetedCertTier(User $user): bool
    {
        $query = UserCertification::where('user_id', $user->id);

        if ($this->target_cert_tier_mode === CertTierTargetMode::AndAbove) {
            return $query->whereIn('cert_tier_id', function ($sub) {
                $sub->select('id')
                    ->from('cert_tiers')
                    ->where('sort_order', '>=', function ($inner) {
                        $inner->select('sort_order')
                            ->from('cert_tiers')
                            ->where('id', $this->target_cert_tier_id);
                    });
            })->exists();
        }

        // Exact mode: identical to the pre-TASK-042 query.
        return $query->where('cert_tier_id', $this->target_cert_tier_id)->exists();
    }

    /** True when status=Active and today falls within [starts_at, ends_at] (ends_at null = open-ended). */
    public function isCurrentlyActive(): bool
    {
        if ($this->status !== PromotionStatus::Active) {
            return false;
        }

        $today = now()->toDateString();

        if ($this->starts_at->toDateString() > $today) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->toDateString() >= $today;
    }
}
