<?php

namespace App\Models;

use App\Enums\MediaProcessingStatus;
use App\Enums\MediaSourceType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Product Catalog — sales/marketing collateral attached to a Product
 * (human-requested; not tied to any BR). Mirrors ClientDocument's
 * shape/pattern (see that model's own comment) but on a PRIVATE disk
 * with a wider viewer circle — see ProductSalesMaterialController.
 *
 * ADR-007 — gained video support (upload or embed). source_type decides
 * which fields are populated: Upload uses file_path/original_filename/
 * mime_type/size_bytes (the original, pre-ADR-007 columns — untouched
 * meaning); Embed uses embed_url only, the rest stay null.
 */
class ProductSalesMaterial extends Model
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
        'material_group',
        'source_type',
        'file_path',
        'original_filename',
        'mime_type',
        'embed_url',
        'processing_status',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => MediaSourceType::class,
            'processing_status' => MediaProcessingStatus::class,
            'size_bytes' => 'integer',
        ];
    }

    /** @return HasMany<SalesMaterialShareLink, $this> ADR-007 */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(SalesMaterialShareLink::class, 'sales_material_id');
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
