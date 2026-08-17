<?php

namespace App\Models;

use App\Enums\CommissionRateType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-025 — BR-2 config for Unilevel manager override commission,
 * keyed by the MANAGER's own cert tier (not the selling agent's).
 * rate_value is basis points/satang exactly like CommissionRule — never
 * a float (BR-3 spirit).
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
}
