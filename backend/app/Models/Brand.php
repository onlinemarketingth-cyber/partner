<?php

namespace App\Models;

use App\Models\Scopes\SharedOrTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Product catalog — ERD-001 §"Product Catalog". Peer domain to Agent
 * (see ERD-001 §2) under Tenancy.
 */
class Brand extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        /*
         * TASK-253 / ADR-040 — company_id may be NULL, meaning a platform
         * brand every company can use.
         *
         * A shared PRODUCT cannot point at a per-company brand: there is one
         * row and many companies. It also cannot leave the column null —
         * ADR-036's Amendment 1 recorded, from a real audit, that
         * products.category_id has three non-display readers, the worst of
         * which is the category-scoped commission rule: it stops matching,
         * the sale falls back to the company default rate, and that wrong
         * payout (BR-2) lands in an immutable ledger row (BR-4).
         *
         * Same scope as theme_presets (TASK-217). No shared row exists yet,
         * so today this returns exactly what plain TenantScope returned.
         */
        static::addGlobalScope(new SharedOrTenantScope);
    }

    protected $fillable = [
        'company_id',
        'name',
        'logo_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
