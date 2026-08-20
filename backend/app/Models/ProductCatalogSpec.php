<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-036 §2/§8 — shared key-value spec rows for a product_catalog_item.
 * Same "new dedicated table, not a widened product_specs" reasoning as
 * ProductCatalogMedia. BR-7: no fixed spec taxonomy, same as ProductSpec
 * (ADR-007).
 */
class ProductCatalogSpec extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalog_item_id',
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

    /** @return BelongsTo<ProductCatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ProductCatalogItem::class);
    }
}
