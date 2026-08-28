<?php

namespace App\Models;

use App\Enums\CommissionPlanType;
use App\Models\Concerns\HasTrackedLink;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant — CLAUDE.md Section 2 (Company/Tenant), Section 5 (Multi-Tenancy).
 *
 * Deliberately does NOT apply TenantScope to itself (a Company is the
 * tenant boundary, not a tenant-scoped resource). Super Admin manages
 * these directly; Company Admin/Agent only ever see their own row via
 * the `company()` relation on User — enforce via CompanyPolicy, not a
 * global scope here.
 */
class Company extends Model
{
    use HasFactory, SoftDeletes;
    use HasTrackedLink;

    protected $fillable = [
        'name',
        // 2026-08-27 — minimum an agent may ask to withdraw, in satang
        // (BR-3). NULL means no minimum, which is a real setting and not a
        // missing one — see the migration's own note.
        'min_withdrawal_satang',
        'slug',
        'is_active',
        'commission_plan_type',
        // ADR-017 (TASK-054) — BR-7 admin-editable payment collection
        // config, shown on the public /pay/{token} page. All nullable.
        'payment_promptpay_id',
        'payment_bank_name',
        'payment_bank_account_number',
        'payment_bank_account_name',
        // ADR-026 §3.3 (TASK-132) — least-specific pipeline-template
        // scope: the journey every product in this company follows unless
        // its category or the product itself overrides it. NULL falls
        // through to the seeded medical_package_default.
        'default_pipeline_template_id',
    ];

    /**
     * ADR-027 — the column defaults to 'manual' in the database, but a
     * DB-level default is not hydrated back onto the model that INSERTed the
     * row. Without this a freshly created Company reads null here, and
     * anything asking "how does this company take money" gets no answer
     * during the request that created it. Same fix, same reason, as
     * User::$attributes['email_notifications_enabled'].
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'payment_provider' => 'manual',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // BR-3 — satang, always an integer. Cast explicitly so a value
            // read back from MySQL is never a numeric string in comparisons.
            'min_withdrawal_satang' => 'integer',
            'commission_plan_type' => CommissionPlanType::class,
            // TASK-056 P2 bugfix — deliberately NOT in $fillable: only
            // ClientCategoryService::ensureDefaults() ever writes this, a
            // client request must never be able to set/clear it directly.
            'client_categories_seeded_at' => 'datetime',
        ];
    }

    /**
     * TASK-183 §3.1 — THE PREDICATE. "May this company operate?"
     *
     * `is_active === true` AND `deleted_at === null`, answered in exactly ONE
     * place. Every enforcement site in the app (the login gate, the
     * authenticated-request middleware, and each public endpoint that acts on
     * behalf of a company) routes through this method or through
     * isOperationalById() / User::belongsToOperationalCompany(), both of which
     * are thin resolvers that call it. Do NOT re-spell the two conditions at a
     * call site: before this task `is_active` was read by nothing but
     * $fillable/casts, a Resource and two Form Requests, and the switch in the
     * Admin UI therefore did nothing at all. A control that visibly does
     * nothing is worse than no control, and two copies of it that disagree
     * would be worse still.
     *
     * Both halves are required, and neither is redundant:
     *   * `is_active = false` is the reversible "suspended" state an Admin sets
     *     from the Manage Companies switch.
     *   * `deleted_at != null` is CompanyService::delete()'s soft delete.
     * A soft-deleted company keeps whatever `is_active` it had at the moment it
     * was deleted — usually true — so checking `is_active` alone would let
     * every user of a deleted tenant carry on working.
     *
     * `=== true` rather than a truthy test is deliberate: the column is cast to
     * boolean, but an unhydrated/partial instance can carry null, and null must
     * mean "no, not proven operational", never "probably fine".
     */
    public function isOperational(): bool
    {
        return $this->is_active === true && $this->deleted_at === null;
    }

    /**
     * TASK-183 §3.1/§3.5 — the by-id resolver over isOperational() above, for
     * the callers that hold a `company_id` rather than a hydrated Company.
     *
     * withTrashed() is load-bearing: SoftDeletingScope would hide a deleted
     * company, this would find nothing, and the caller would then have to
     * decide what "not found" means — which is exactly the branch we do not
     * want duplicated. Here it is decided once: a company_id that resolves to
     * NOTHING (hard-deleted, or a dangling reference) is NOT operational. Fail
     * closed.
     *
     * A null $companyId is likewise NOT operational. This method answers a
     * question about a specific tenant; "there is no tenant" is not a yes.
     * The one caller for whom no tenant is legitimate — a Super Admin — is
     * handled explicitly in User::belongsToOperationalCompany(), so that the
     * exemption is written down in one visible place instead of falling out of
     * a null default here.
     */
    public static function isOperationalById(?int $companyId): bool
    {
        if ($companyId === null) {
            return false;
        }

        return static::withTrashed()->find($companyId)?->isOperational() === true;
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Brand, $this> */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /** @return HasMany<ProductCategory, $this> */
    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<Client, $this> Customers referred within this company. */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<Referral, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    /** @return HasMany<CompanyInviteCode, $this> ADR-005 — self-registration invite codes. */
    public function inviteCodes(): HasMany
    {
        return $this->hasMany(CompanyInviteCode::class);
    }

    /** @return HasMany<CommissionOverrideRule, $this> TASK-025 — Unilevel manager override rates. */
    public function commissionOverrideRules(): HasMany
    {
        return $this->hasMany(CommissionOverrideRule::class);
    }

    /** @return HasOne<CommissionBinarySetting, $this> ADR-006 Round 4 — only when commission_plan_type = binary. */
    public function commissionBinarySetting(): HasOne
    {
        return $this->hasOne(CommissionBinarySetting::class);
    }

    /** @return HasMany<BinaryLegVolume, $this> ADR-006 Round 4. */
    public function binaryLegVolumes(): HasMany
    {
        return $this->hasMany(BinaryLegVolume::class);
    }

    /** @return HasMany<BinaryMatchingCycle, $this> ADR-006 Round 4. */
    public function binaryMatchingCycles(): HasMany
    {
        return $this->hasMany(BinaryMatchingCycle::class);
    }

    /** @return HasOne<VideoProcessingSetting, $this> ADR-007 — BR-7 admin-editable video compression limits, optional override. */
    public function videoProcessingSetting(): HasOne
    {
        return $this->hasOne(VideoProcessingSetting::class);
    }

    /** @return HasOne<CommissionMatrixSetting, $this> ADR-011/TASK-030 — only when commission_plan_type = matrix. */
    public function commissionMatrixSetting(): HasOne
    {
        return $this->hasOne(CommissionMatrixSetting::class);
    }

    /** @return HasMany<MatrixPlacement, $this> ADR-011/TASK-030. */
    public function matrixPlacements(): HasMany
    {
        return $this->hasMany(MatrixPlacement::class);
    }

    /** @return HasMany<CommissionMatrixLevelRate, $this> ADR-011/TASK-030. */
    public function commissionMatrixLevelRates(): HasMany
    {
        return $this->hasMany(CommissionMatrixLevelRate::class);
    }

    /** @return HasMany<AgentRank, $this> ADR-011/TASK-031 — shared by Stairstep/Breakaway + Generation. */
    public function agentRanks(): HasMany
    {
        return $this->hasMany(AgentRank::class);
    }

    /** @return HasOne<AgentRankSetting, $this> ADR-011/TASK-031 — trailing-volume window + recalculation cadence. */
    public function agentRankSetting(): HasOne
    {
        return $this->hasOne(AgentRankSetting::class);
    }

    /** @return HasMany<CommissionGenerationRule, $this> ADR-011/TASK-031 — only when commission_plan_type = generation. */
    public function commissionGenerationRules(): HasMany
    {
        return $this->hasMany(CommissionGenerationRule::class);
    }

    /** @return HasOne<CommissionGenerationSetting, $this> ADR-011/TASK-031 — max_generation_depth cap. */
    public function commissionGenerationSetting(): HasOne
    {
        return $this->hasOne(CommissionGenerationSetting::class);
    }

    /** @return HasMany<AffiliateLink, $this> ADR-011/TASK-032. */
    public function affiliateLinks(): HasMany
    {
        return $this->hasMany(AffiliateLink::class);
    }

    /** @return HasOne<AffiliateAttributionSetting, $this> ADR-011/TASK-032. */
    public function affiliateAttributionSetting(): HasOne
    {
        return $this->hasOne(AffiliateAttributionSetting::class);
    }

    /** @return HasOne<CompanyThemeSetting, $this> ADR-018/TASK-055 — per-company white-label theme. */
    public function themeSetting(): HasOne
    {
        return $this->hasOne(CompanyThemeSetting::class);
    }

    /** @return HasMany<PipelineTemplate, $this> ADR-026/TASK-132 — this tenant's pipeline templates. */
    public function pipelineTemplates(): HasMany
    {
        return $this->hasMany(PipelineTemplate::class);
    }

    /** @return BelongsTo<PipelineTemplate, $this> ADR-026 §3.3 — company-wide default journey, null = seeded medical_package_default. */
    public function defaultPipelineTemplate(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplate::class, 'default_pipeline_template_id');
    }
}
