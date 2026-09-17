<?php

namespace App\Models;

use App\Enums\CommissionBasis;
use App\Enums\CommissionOverrideMode;
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
        /*
         * 2026-09-17 — THE SUPPLIER COLUMNS ARE GONE FROM THIS MODEL.
         *
         * There were nine of them here for one day: is_supplier, a GP mode and
         * value, a release trigger, a withdrawal floor, a withholding rate and
         * three payout bank fields. They said that a company could ALSO be a
         * supplier, which the owner rejected as the wrong shape entirely:
         *
         *   "Company Partner = Supplier ต้องแยกจาก Company เดิม แต่คุณเอา UI
         *    ไปใส่ที่ company เดิมที่เป็นค่าคอม ผิดทั้งหมดเลย"
         *
         * A Company is a TENANT — it sells through us and we pay COMMISSION to
         * its agents. A Supplier is a COUNTERPARTY — it supplies goods and we
         * pay it the sale price less commission less GP. Not one of those nine
         * fields means anything in the first sentence. They now live on
         * App\Models\Supplier, in their own table, edited on their own
         * screen.
         *
         * The columns themselves are still on `companies` until the follow-up
         * migration drops them (see the repoint migration for why that is a
         * separate deploy). Nothing reads them — they are deliberately absent
         * from $fillable and casts() so that a stray write cannot resurrect
         * the confusion while they wait to be dropped.
         */
        'commission_plan_type',
        // 2026-09-12 — the second half of "how does this company pay":
        // commission_plan_type says WHO is paid, this says what a
        // percentage is a percentage OF (the sale price, or the product's
        // PV). Defaults to 'price' for every company that never touches
        // it. Never read this column directly from calculation code — go
        // through CommissionBasisResolver, which owns the missing-PV
        // fallback that must not be duplicated.
        'commission_basis',
        // 2026-09-13 — where the team leader's share comes from: on top of the
        // seller's commission, or out of it (and out of WHICH base). See
        // App\Enums\CommissionOverrideMode — the two deduct modes differ by
        // 33x on the same inputs, which is why the enum names the base.
        'commission_override_mode',
        /*
         * 2026-09-15 — the user row that IS this company inside its own
         * hierarchy, so a leader override has somebody to reach when the
         * seller has no human manager. See the migration for why this is the
         * only place that fact is stored.
         *
         * Fillable, but nothing should ever write it directly:
         * CommissionHouseAccountService owns both ends of the change (the
         * pointer AND every agent's manager_id), and setting the column alone
         * produces a company that believes it has a house account nobody
         * reports to.
         */
        'commission_house_user_id',
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

    /*
     * 2026-09-03 — the 'manual' default is GONE, deliberately.
     *
     * `companies.payment_provider` no longer answers "how does this company
     * take money"; bank transfer / PromptPay is always available and is not
     * a setting. The column now answers only "which ONLINE gateway is
     * switched on", and the honest answer for a company that has not turned
     * one on is NULL — which is also the column's own default since
     * 2026_09_03_100000.
     *
     * Re-adding a default here would hand every new company a fake active
     * gateway, and activeConfig() would then offer a card form backed by the
     * manual driver.
     */

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // BR-3 — satang, always an integer. Cast explicitly so a value
            // read back from MySQL is never a numeric string in comparisons.
            'min_withdrawal_satang' => 'integer',
            'commission_plan_type' => CommissionPlanType::class,
            'commission_basis' => CommissionBasis::class,
            'commission_override_mode' => CommissionOverrideMode::class,
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

    /**
     * 2026-09-15 — the company's own position in its hierarchy, or null.
     *
     * `withoutGlobalScopes()` because this is read from inside the commission
     * walk and from the withdrawal guard, where the acting user is whoever is
     * being paid or asking — a TenantScope narrowed to THEM would hide the
     * house account of the company that owns the sale. The relation is
     * already keyed by this company's own column, so there is nothing wider
     * it could reach.
     *
     * @return BelongsTo<User, $this>
     */
    public function commissionHouseAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commission_house_user_id')->withoutGlobalScopes();
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
