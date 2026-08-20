<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ADR-036 §2 — the shared ("global") product identity a company's own
 * `products` row can opt into via `products.catalog_item_id`. No
 * company_id, no TenantScope — Super-Admin-only write
 * (ProductCatalogItemPolicy). Holds exactly what ADR-036's decision table
 * says must be IDENTICAL across every company selling this product: name,
 * description, spec_description, brand, category. price_satang,
 * commission config, is_active, etc. all stay on each company's own
 * `products` row (ADR-036 §3) — never duplicated here.
 *
 * `products()` deliberately goes through Product's own TenantScope
 * (unchanged) — a Company Admin querying this relation only ever sees
 * their own company's linked row, which is correct: only Super Admin
 * manages catalog items in the first place (ProductCatalogItemPolicy),
 * so the only caller who queries this relation across every company is
 * already exempt from TenantScope.
 */
class ProductCatalogItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'catalog_brand_id',
        'catalog_category_id',
        'name',
        'description',
        'spec_description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<CatalogBrand, $this> */
    public function catalogBrand(): BelongsTo
    {
        return $this->belongsTo(CatalogBrand::class);
    }

    /** @return BelongsTo<CatalogCategory, $this> */
    public function catalogCategory(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class);
    }

    /** @return HasMany<Product, $this> every company's product row linked to this shared identity. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'catalog_item_id');
    }

    /** @return HasMany<ProductCatalogMedia, $this> ADR-036 §2 — shared media gallery. */
    public function media(): HasMany
    {
        // Same cover-then-detail ordering rationale as Product::media()
        // (TASK-097) — kept identical so a linked product's gallery
        // behaves exactly like a standalone product's.
        // Explicit FK: the column is catalog_item_id (migration
        // 2026_08_18_120300), NOT Eloquent's guessed
        // product_catalog_item_id — same naming as products.catalog_item_id
        // so every "which catalog item does this belong to" column reads
        // identically across the schema.
        return $this->hasMany(ProductCatalogMedia::class, 'catalog_item_id')
            ->orderBy('purpose')
            ->orderBy('sort_order');
    }

    /** @return HasMany<ProductCatalogSpec, $this> ADR-036 §2 — shared key-value spec sheet. */
    public function specs(): HasMany
    {
        // Explicit FK — see media() above (column is catalog_item_id,
        // migration 2026_08_18_120400).
        return $this->hasMany(ProductCatalogSpec::class, 'catalog_item_id')->orderBy('sort_order');
    }
}
