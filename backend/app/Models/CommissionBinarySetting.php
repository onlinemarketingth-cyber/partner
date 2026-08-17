<?php

namespace App\Models;

use App\Enums\BinaryCycleFrequency;
use App\Enums\CommissionRateType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-006 Round 4 — one row per company, only relevant when
 * Company::commission_plan_type = binary. Schema only for now — no
 * CommissionService reads this yet (Binary is "under development").
 */
class CommissionBinarySetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'matched_rate_type',
        'matched_rate_value',
        'cycle_frequency',
        'payout_cap_satang',
        'carry_over_unmatched',
    ];

    protected function casts(): array
    {
        return [
            'matched_rate_type' => CommissionRateType::class,
            'matched_rate_value' => 'integer',
            'cycle_frequency' => BinaryCycleFrequency::class,
            'payout_cap_satang' => 'integer',
            'carry_over_unmatched' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
