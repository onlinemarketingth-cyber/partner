<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ADR-036 §2 — the shared ("global") category standard for cross-company
 * catalog items. Same no-company_id / no-TenantScope / Super-Admin-only-
 * write contract as CatalogBrand — see its docblock for the full
 * reasoning. Column shape mirrors ProductCategory (name, icon, sort_order,
 * is_active, soft-deletes) minus company_id and pipeline_template_id
 * (pipeline template resolution stays a per-company/per-product concern,
 * untouched by ADR-036 — see ADR-026, unrelated to this ADR).
 */
class CatalogCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        // TASK-068 / ADR-020 row 3 — same curated-icon-whitelist convention
        // as product_categories.icon.
        'icon',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ProductCatalogItem, $this> */
    public function catalogItems(): HasMany
    {
        return $this->hasMany(ProductCatalogItem::class);
    }
}
