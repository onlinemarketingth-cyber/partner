<?php

namespace App\Models;

use App\Enums\PipelineStage;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Referral & Pipeline — ERD-001 §"Referral & Pipeline" (rev. 3). The
 * transaction connecting Agent, Customer (Client) and Product.
 */
class Referral extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'client_id',
        'agent_id',
        'product_id',
        'branch',
        'preferred_time',
        'current_stage',
        'meeting_number',
        'submitted_at',
        // TASK-024 — opt-in renewal schedule, stamped by CommissionService
        // only when a matching commission_rule has a renewal rate.
        'next_renewal_date',
        // TASK-026 — split commission, nullable pair (both-or-neither,
        // enforced in ReferralService, not the DB).
        'co_agent_id',
        'split_percentage',
        // ADR-011/TASK-032 — set only when this referral originated from
        // a tracked affiliate link AND a valid click existed within the
        // attribution window (see AffiliateLeadCaptureService).
        'affiliate_link_id',
        // ADR-026 §3.4 (TASK-132) — the pipeline template SNAPSHOT.
        // Stamped once, at creation, by ReferralService/AffiliateLeadCaptureService
        // from PipelineTemplateResolver; never re-resolved afterwards, for
        // the same reason BR-4's ledger is immutable — editing a template
        // must not reroute or strand a customer already mid-journey.
        // NULL on every pre-ADR-026 referral: TASK-133 treats that as
        // "fall back to PipelineStage's default edges".
        'pipeline_template_id',
    ];

    protected function casts(): array
    {
        return [
            'preferred_time' => 'datetime',
            'current_stage' => PipelineStage::class,
            'meeting_number' => 'integer',
            'submitted_at' => 'datetime',
            'next_renewal_date' => 'date',
            'split_percentage' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** TASK-026 — the second agent sharing this referral's commission, if any. */
    public function coAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'co_agent_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<PipelineStageLog, $this> */
    public function stageLogs(): HasMany
    {
        return $this->hasMany(PipelineStageLog::class);
    }

    /** @return HasMany<CommissionLedger, $this> */
    public function commissionLedgerEntries(): HasMany
    {
        return $this->hasMany(CommissionLedger::class);
    }

    /**
     * ADR-017 / TASK-176 §1.1 — the payment-collection records bound to this
     * referral. hasMany, NOT hasOne: a referral may accumulate several orders
     * over its life (one cancelled, one live), and OrderService::createForReferral
     * only blocks a *second active* one. Which of them a board may act on is
     * OrderService::actionableOrder()'s decision, not this relation's.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return BelongsTo<AffiliateLink, $this> ADR-011/TASK-032 — null unless this referral is an attributed affiliate conversion. */
    public function affiliateLink(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class);
    }

    /** @return BelongsTo<PipelineTemplate, $this> ADR-026 §3.4 — the journey THIS referral was created under, snapshotted. */
    public function pipelineTemplate(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplate::class);
    }
}
