<?php

namespace App\Models;

use App\Enums\MediaProcessingStatus;
use App\Enums\MediaSourceType;
use App\Enums\ProductMediaPurpose;
use App\Enums\ProductMediaType;
use App\Models\Scopes\TenantScope;
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

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
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
