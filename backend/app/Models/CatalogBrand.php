<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ADR-036 §2 — the shared ("global") brand standard for cross-company
 * catalog items. Deliberately NOT company-scoped: no company_id column,
 * no TenantScope global scope (unlike Brand). Every company that links a
 * product to a shared catalog item sees the same brand name — that is
 * the whole point (human decision, ADR-036's decision table: "มาตรฐาน
 * เดียวกัน"). Write access is Super-Admin-only (CatalogBrandPolicy) —
 * access control lives entirely in the Policy/Service layer since there
 * is no company_id to filter a query by.
 *
 * Column shape deliberately mirrors Brand (name, logo_path, is_active,
 * soft-deletes) so CatalogBrandResource can output the same shape as
 * BrandResource for a catalog-linked product (see ProductResource).
 */
class CatalogBrand extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
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

    /** @return HasMany<ProductCatalogItem, $this> */
    public function catalogItems(): HasMany
    {
        return $this->hasMany(ProductCatalogItem::class);
    }
}
