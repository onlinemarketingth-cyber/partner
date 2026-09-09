<?php

namespace App\Models;

use App\Enums\MediaProcessingStatus;
use App\Enums\MediaSourceType;
use App\Enums\ProductMediaPurpose;
use App\Enums\ProductMediaType;
use App\Models\Scopes\SharedOrTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-007 — Product's image/video gallery (Amazon-style product detail).
 * source_type decides which of file_path/embed_url is populated (never
 * both — enforced in ProductMediaService, not the DB).
 */
class ProductMedia extends Model
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
        'uploaded_by_user_id',
        'media_type',
        'source_type',
        'purpose',
        'file_path',
        'embed_url',
        'thumbnail_path',
        'is_primary',
        'sort_order',
        'processing_status',
    ];

    protected function casts(): array
    {
        return [
            'media_type' => ProductMediaType::class,
            'source_type' => MediaSourceType::class,
            'purpose' => ProductMediaPurpose::class,
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'processing_status' => MediaProcessingStatus::class,
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

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
