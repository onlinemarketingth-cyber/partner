<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Product catalog — ERD-001 §"Product Catalog". Independent of Brand
 * (ERD-001 open question #1 — proposed default).
 */
class ProductCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'name',
        // TASK-068 / ADR-020 row 3 — Icon.vue icon-name string, validated
        // server-side against App\Support\CuratedIcons::WHITELIST (see
        // Store/UpdateProductCategoryRequest). Nullable = no icon chosen.
        'icon',
        'sort_order',
        'is_active',
        // ADR-026 §3.3 (TASK-132) — middle scope of the pipeline-template
        // chain: applies to every product in this category that has no
        // template of its own. NULL = inherit the company default.
        'pipeline_template_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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
        return $this->hasMany(Product::class, 'category_id');
    }

    /** @return BelongsTo<PipelineTemplate, $this> ADR-026 §3.3 — category-level template, null = inherit the company default. */
    public function pipelineTemplate(): BelongsTo
    {
        return $this->belongsTo(PipelineTemplate::class);
    }
}
