<?php

namespace App\Models;

use App\Enums\CommissionRateType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-011 Section 3b (TASK-030) — Matrix payout rate keyed by LEVEL
 * (hops up matrix_placements.parent_id from the seller), capped at
 * commission_matrix_settings.depth. See that migration's own comment
 * for why this is level-keyed rather than cert-tier-keyed like
 * commission_override_rules (Unilevel).
 */
class CommissionMatrixLevelRate extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'level',
        'rate_type',
        'rate_value',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
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
}
