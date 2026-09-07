<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-253 / ADR-040 — one company's price and on/off switch for a shared
 * product.
 *
 * Plain TenantScope, not SharedOrTenantScope: unlike the product it points
 * at, this row is never shared. It says what ONE company decided, and a
 * company reading another's price is exactly the leak BR-6 exists to
 * prevent — which is also why the scope stays on even though the parent
 * product is visible to everybody.
 *
 * `price_satang` null means "no decision here, use the product's own price"
 * (ADR-040, the human's answer). `is_active` false means "not on sale here",
 * and it has no fallback: knowing what something would cost is not deciding
 * to sell it.
 */
class CompanyProductSetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'product_id',
        'price_satang',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // BR-3 — satang stays an integer end to end; nothing here divides.
            'price_satang' => 'integer',
            'is_active' => 'boolean',
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
}
