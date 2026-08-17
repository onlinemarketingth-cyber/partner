<?php

namespace App\Models;

use App\Enums\CommissionRateType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-011 Section 3c (TASK-031) — Generation plan config, keyed by
 * generation_number (count of breakaway-rank ancestors walked, not raw
 * manager_id hops — see GenerationCommissionService::payGenerationOverrides()).
 * Mirrors CommissionOverrideRule/CommissionMatrixLevelRate's shape.
 */
class CommissionGenerationRule extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'generation_number',
        'rate_type',
        'rate_value',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'generation_number' => 'integer',
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
