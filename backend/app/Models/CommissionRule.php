<?php

namespace App\Models;

use App\Enums\CommissionRateType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-2 config — "rate = cert tier x package sold... never hardcode."
 * rate_value is basis points when rate_type=percentage, THB cents
 * (BR-3) when rate_type=fixed_satang.
 */
class CommissionRule extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'cert_tier_id',
        'product_id',
        // ADR-011/TASK-028 — at most one of product_id/product_category_id
        // is ever set (enforced in StoreCommissionRuleRequest); both null
        // = company-wide default for the cert tier. See
        // CommissionService's resolution order docblock.
        'product_category_id',
        'rate_type',
        'rate_value',
        'effective_from',
        'effective_to',
        // TASK-024 — optional renewal-year rate, fully opt-in (BR-7).
        'renewal_rate_type',
        'renewal_rate_value',
        'renewal_recurs',
    ];

    protected function casts(): array
    {
        return [
            'rate_type' => CommissionRateType::class,
            'rate_value' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'renewal_rate_type' => CommissionRateType::class,
            'renewal_rate_value' => 'integer',
            'renewal_recurs' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<CertTier, $this> */
    public function certTier(): BelongsTo
    {
        return $this->belongsTo(CertTier::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductCategory, $this> ADR-011/TASK-028. */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }
}
