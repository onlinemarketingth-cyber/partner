<?php

namespace App\Models;

use App\Enums\AgentRankRecalculationFrequency;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-011 Section 3c (TASK-031) — one row per company: trailing-volume
 * window + recalculation cadence for RecalculateAgentRanks (see
 * StairstepCommissionService::recalculateRanks()).
 */
class AgentRankSetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'trailing_window_days',
        'recalculation_frequency',
        'last_recalculated_at',
    ];

    protected function casts(): array
    {
        return [
            'trailing_window_days' => 'integer',
            'recalculation_frequency' => AgentRankRecalculationFrequency::class,
            'last_recalculated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
