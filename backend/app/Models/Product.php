<?php

namespace App\Models;

use App\Enums\AffiliateOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Product catalog — ERD-001 §"Product Catalog". CLAUDE.md §2 "Package /
 * Product". price_satang is BR-3 integer THB cents — the 8,900/9,900 THB
 * figures are seed data (BR-7), never hardcoded in code.
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'brand_id',
        'category_id',
        // ADR-036 §2/§3 (TASK-211/212) — opt-in link to a shared
        // product_catalog_items row. NULL = standalone (today's behavior,
        // every existing row). When set, this product's OWN name/brand_id/
        // category_id/description/spec_description become vestigial — the
        // effective_ methods below resolve identity from the catalog item
        // instead. Super-Admin-only to set/clear (ProductCatalogLinkService),
        // never touched by StoreProductRequest/UpdateProductRequest directly.
        'catalog_item_id',
        'name',
        'price_satang',
        'description',
        'spec_description',
        'is_active',
        'commission_plan_type',
        // TASK-194 §3.1 — only read when effectivePlanType() is Affiliate;
        // NULL = 'additive' at calculation time. See
        // effectiveAffiliateOverrideMode() below — same "never read the
        // raw column, always go through the resolver" rule as
        // effectivePlanType() itself.
        'affiliate_override_mode',
        // TASK-197 §2.1 — the per-product %/fixed-amount FORMAT choice,
        // hoisted off individual commission_rules rows (see
        // CommissionRule::$rate_type). NULL = "not yet configured" — the
        // frontend defaults a fresh product-scoped rule form to
        // 'percentage' when null, same as the old per-rule default; the
        // FIRST commission_rules row created for this product stamps its
        // own rate_type here (CommissionRuleService::create()), and every
        // later rule for this product must match it
        // (ValidatesCommissionRateTypeConsistency). Never touched by a
        // migration/backfill — going-forward only, per TASK-197 §1.
        'commission_rate_type',
        // ADR-026 §3.3 (TASK-132) — the MOST SPECIFIC pipeline-template
        // scope. NULL = inherit from the category, then the company, then
        // the seeded medical_package_default. Never read this directly:
        // go through PipelineTemplateResolver::resolveForProduct(), the
        // single place the inherit chain lives (same rule as
        // effectivePlanType() below).
        'pipeline_template_id',
        // ADR-033 (TASK-189) §2.3/§2.5 — BR-7 admin-editable, never
        // hardcoded. Nullable = unlimited / never expires. Snapshotted onto
        // order_vouchers at issuance (OrderVoucherService::issueFor()),
        // never read live at redemption time.
        'voucher_usage_quota',
        'voucher_validity_days',
        'requires_shipping',
    ];

    protected function casts(): array
    {
        return [
            'price_satang' => 'integer',
            'is_active' => 'boolean',
            'commission_plan_type' => CommissionPlanType::class,
            'affiliate_override_mode' => AffiliateOverrideMode::class,
            'commission_rate_type' => CommissionRateType::class,
            'voucher_usage_quota' => 'integer',
            'voucher_validity_days' => 'integer',
            'requires_shipping' => 'boolean',
        ];
    }

    /**
     * ADR-011 Section 1 (TASK-027): a product may override the company's
     * plan type; NULL means "inherit the company's default." This is the
     * single place plan-type resolution happens — callers (CommissionService
     * and future TASK-029..032 engines) must always go through this, never
     * read $product->commission_plan_type or $company->commission_plan_type
     * directly, so the inherit rule can't be duplicated/drifted elsewhere.
     */
    public function effectivePlanType(): CommissionPlanType
    {
        return $this->commission_plan_type ?? $this->company->commission_plan_type;
    }

    /**
     * TASK-194 §3.1 — NULL means "additive" (the safe default that
     * mirrors Unilevel's existing override behaviour). Unlike
     * effectivePlanType(), there is no company-level fallback to inherit
     * from — this is purely a per-product choice, only ever read by
     * CommissionService when effectivePlanType() is Affiliate.
     */
    public function effectiveAffiliateOverrideMode(): AffiliateOverrideMode
    {
        return $this->affiliate_override_mode ?? AffiliateOverrideMode::Additive;
    }

    /**
     * ADR-036 §2/§3 — a catalog-linked product's identity (name, brand,
     * category, description, spec_description) is owned by the shared
     * product_catalog_items row, not this product's own columns (which
     * become vestigial once catalog_item_id is set — see
     * 2026_08_18_120600_make_brand_category_name_nullable_on_products_table).
     * Every consumer (ProductResource, referral/order/commission displays
     * that read a product's name) must go through these five resolvers,
     * never $product->name / ->brand / ->category / ->description /
     * ->spec_description directly, so "catalog wins when linked" can't be
     * duplicated/drifted elsewhere — same discipline as effectivePlanType()
     * above. Simple two-way resolution (own row ?? catalog item), unlike
     * the pipeline template chain's three-level scope — no dedicated
     * Resolver service needed, this Model-method style is the right-sized
     * match (see PipelineTemplateResolver's docblock for when THAT
     * heavier pattern is actually warranted: a >2-level chain queried
     * across a paginated list).
     */
    public function effectiveName(): ?string
    {
        return $this->catalog_item_id ? $this->catalogItem?->name : $this->name;
    }

    public function effectiveDescription(): ?string
    {
        return $this->catalog_item_id ? $this->catalogItem?->description : $this->description;
    }

    public function effectiveSpecDescription(): ?string
    {
        return $this->catalog_item_id ? $this->catalogItem?->spec_description : $this->spec_description;
    }

    /** @return \App\Models\Brand|\App\Models\CatalogBrand|null */
    public function effectiveBrand(): Brand|CatalogBrand|null
    {
        return $this->catalog_item_id ? $this->catalogItem?->catalogBrand : $this->brand;
    }

    /** @return \App\Models\ProductCategory|\App\Models\CatalogCategory|null */
    public function effectiveCategory(): ProductCategory|CatalogCategory|null
    {
        return $this->catalog_item_id ? $this->catalogItem?->catalogCategory : $this->category;
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Brand, $this> this product's OWN brand row — ignored once catalog_item_id is set, see effectiveBrand(). */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<ProductCategory, $this> this product's OWN category row — ignored once catalog_item_id is set, see effectiveCategory(). */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /** @return BelongsTo<ProductCatalogItem, $this> ADR-036 §2/§3 — the shared identity this product opts into, null = standalone. */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ProductCatalogItem::class);
    }

    /** @return BelongsTo<PipelineTemplate, $this> ADR-026 §3.3 — this product's OWN template override, null = inherit. */
    public function pipelineTemplate(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplate::class);
    }

    /** @return HasMany<CommissionRule, $this> */
    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    /** @return HasMany<Module, $this> */
    public function modules(): HasMany
    {
        return $this->hasMany(Module::class);
    }

    /** @return HasMany<ProductSalesMaterial, $this> Human-requested sales/marketing collateral, not tied to any BR. */
    public function salesMaterials(): HasMany
    {
        return $this->hasMany(ProductSalesMaterial::class);
    }

    /** @return HasMany<ProductMedia, $this> ADR-007 — image/video gallery. */
    public function media(): HasMany
    {
        // TASK-097 — cover photos lead. Consumers that render ONE image
        // (storefront card) or a carousel the customer sees first (public
        // share page) must not have a detail-gallery screenshot handed to
        // them just because it was uploaded earlier. `purpose` sorts
        // 'cover' before 'detail' alphabetically, which is a coincidence
        // worth stating out loud rather than relying on silently — if a
        // third purpose is ever added, order it explicitly here.
        return $this->hasMany(ProductMedia::class)->orderBy('purpose')->orderBy('sort_order');
    }

    /** @return HasMany<ProductSpec, $this> ADR-007 — admin-editable key-value spec sheet. */
    public function specs(): HasMany
    {
        return $this->hasMany(ProductSpec::class)->orderBy('sort_order');
    }

    /** @return HasMany<ProductSpecAttachment, $this> ADR-008 — spec image/PDF gallery. */
    public function specAttachments(): HasMany
    {
        return $this->hasMany(ProductSpecAttachment::class)->orderBy('sort_order');
    }
}
