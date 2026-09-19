<?php

namespace App\Models;

use App\Enums\AgentRankRecalculationFrequency;
use App\Enums\AgentRankVolumeScope;
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
        'volume_scope',
        'recalculation_frequency',
        'last_recalculated_at',
    ];

    protected function casts(): array
    {
        return [
            'trailing_window_days' => 'integer',
            'volume_scope' => AgentRankVolumeScope::class,
            'recalculation_frequency' => AgentRankRecalculationFrequency::class,
            'last_recalculated_at' => 'datetime',
        ];
    }

    /**
     * Whose sales count toward a rank — never read the raw column.
     *
     * A row written before the column existed reads back NULL rather than
     * the column default, and a hand-edited or half-migrated value reads
     * back as an unrecognised string that the cast would throw on. Both
     * resolve here to Personal, which is what the code did before the
     * column existed — the fail-safe answer is the old behaviour, not an
     * exception on a scheduled job nobody is watching.
     */
    public function volumeScope(): AgentRankVolumeScope
    {
        $raw = $this->getAttributes()['volume_scope'] ?? null;

        return $raw === null
            ? AgentRankVolumeScope::default()
            : (AgentRankVolumeScope::tryFrom((string) $raw) ?? AgentRankVolumeScope::default());
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
