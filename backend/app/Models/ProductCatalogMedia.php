<?php

namespace App\Models;

use App\Enums\MediaProcessingStatus;
use App\Enums\MediaSourceType;
use App\Enums\ProductMediaPurpose;
use App\Enums\ProductMediaType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-036 §2/§8 — shared media gallery for a product_catalog_item. A
 * deliberately separate table/model from ProductMedia (see the
 * product_catalog_media migration's docblock) — same enums/column
 * shapes, no company_id, no TenantScope. Access control is "can you see
 * this catalog item at all", enforced in the Service layer
 * (ProductCatalogItemPolicy), not by a tenant filter on this table.
 */
class ProductCatalogMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalog_item_id',
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

    /** @return BelongsTo<ProductCatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ProductCatalogItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
