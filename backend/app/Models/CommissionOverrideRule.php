<?php

namespace App\Models;

use App\Enums\CommissionRateType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-2 config for the team-leader override commission (Unilevel's manager
 * chain and Affiliate's single hop both read it).
 *
 * TASK-025 keyed this by the MANAGER's own cert tier. TASK-214 replaced
 * that with the same product > category > company scope the selling
 * agent's CommissionRule uses, on the human's ruling of 2026-08-19 — see
 * the 2026_09_03_090000 migration's docblock for why the tier column is
 * still here and what it now means.
 *
 * rate_value is basis points/satang exactly like CommissionRule — never a
 * float (BR-3 spirit).
 */
class CommissionOverrideRule extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        // TASK-214 — the scope pair, same contract as CommissionRule:
        // at most ONE may be set; both null = the company-wide default.
        'product_id',
        'product_category_id',
        // TASK-214 — retained for legacy rows only. Resolution no longer
        // reads it (human ruling 2026-08-19: "ไม่ต้องผูก"); it survives so
        // an operator collapsing pre-TASK-214 per-tier rows can still see
        // what each one meant. Nullable on new rows.
        'manager_cert_tier_id',
        'rate_type',
        'rate_value',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'rate_type' => CommissionRateType::class,
            'rate_value' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<CertTier, $this> */
    public function managerCertTier(): BelongsTo
    {
        return $this->belongsTo(CertTier::class, 'manager_cert_tier_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }
}
