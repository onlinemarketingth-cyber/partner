<?php

namespace App\Models;

use App\Enums\MediaProcessingStatus;
use App\Enums\MediaSourceType;
use App\Enums\ProductSpecAttachmentType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-008 — Product's spec image/PDF gallery, separate from the
 * hero/thumbnail gallery (ProductMedia, ADR-007). source_type decides
 * which of file_path/embed_url is populated (never both — enforced in
 * ProductSpecAttachmentService, not the DB).
 */
class ProductSpecAttachment extends Model
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
        'file_path',
        'embed_url',
        'thumbnail_path',
        'page_count',
        'sort_order',
        'processing_status',
    ];

    protected function casts(): array
    {
        return [
            'media_type' => ProductSpecAttachmentType::class,
            'source_type' => MediaSourceType::class,
            'page_count' => 'integer',
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
