<?php

namespace App\Models;

use App\Models\Scopes\SharedOrTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-007 — admin-editable key-value product spec (BR-7: no fixed
 * taxonomy; works for a physical-goods spec set or a health-package
 * spec set equally, since spec_group/spec_key are free text).
 */
class ProductSpec extends Model
{
    use HasFactory;

    /*
     * 2026-09-09 — SharedOrTenantScope, not TenantScope.
     *
     * `company_id` is nullable here now: a platform-owned product (ADR-040)
     * has no company, and neither does its content. Plain TenantScope
     * (`where company_id = :own`) silently excludes NULL, so a Company Admin
     * would see the shared product with an empty gallery and every one of its
     * rows would 404 through route-model binding. Company A still cannot see
     * company B's — that guarantee is unchanged.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new SharedOrTenantScope);
    }

    protected $fillable = [
        'company_id',
        'product_id',
        'spec_group',
        'spec_key',
        'spec_value',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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
