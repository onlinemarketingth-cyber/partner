<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-068 / ADR-020 row 2 — admin-curated storefront banner carousel.
 * Tenant-scoped (§5/BR-6).
 *
 * TASK-073 (2026-08-02, human-confirmed) supersedes ADR-020 decision #2
 * ("a banner always links to exactly one Product, no external URLs, no
 * free-text links this round"): `link_type` now selects which ONE of
 * `product_id` / `external_url` / `internal_path` is populated —
 * enforced in the Form Requests, not a DB CHECK constraint.
 */
class StorefrontBanner extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Fix (2026-08-03, surfaced by `php artisan test`: two assertions got
     * null where 'top' / 'product' were expected).
     *
     * Both columns carry a DB-level default, but a DB default is only
     * applied by the database on INSERT — Eloquent never reads it back,
     * so the freshly created model (and therefore the JSON the API
     * returns to the Admin UI right after a create) had
     * placement/link_type = null until the row was re-fetched. The Vue
     * Banners tab renders straight off that create response, so the new
     * banner showed no placement/link type until a manual reload.
     *
     * Declaring the defaults here fixes both at once: the attribute is
     * present on the in-memory model, the INSERT sends the value
     * explicitly, and the enum casts still apply on access. Values must
     * stay in sync with the two migrations' ->default(...) and with
     * StorefrontBannerPlacement::Top / StorefrontBannerLinkType::Product.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'placement' => 'top',
        'link_type' => 'product',
    ];

    protected $fillable = [
        'company_id',
        'product_id',
        'link_type',
        'external_url',
        'internal_path',
        'image_path',
        'title',
        'placement',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'link_type' => \App\Enums\StorefrontBannerLinkType::class,
            'placement' => \App\Enums\StorefrontBannerPlacement::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
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
}
