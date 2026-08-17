<?php

namespace App\Models;

use App\Enums\CommissionRateType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-011 Section 3c (TASK-031) — company-configurable sales-volume rank
 * ladder shared by Stairstep/Breakaway and Generation plan types.
 * rate_type/rate_value: this rank's OWN direct-sale commission rate,
 * used by StairstepCommissionService's differential-override walk (see
 * that class's own docblock — a human design decision, not invented).
 * is_breakaway_rank: reaching this rank cuts a downline leg's commission
 * tie to its former upline (Stairstep) and marks a "generation boundary"
 * (Generation).
 */
class AgentRank extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'name',
        'volume_threshold',
        'sort_order',
        'rate_type',
        'rate_value',
        'is_breakaway_rank',
    ];

    protected function casts(): array
    {
        return [
            'volume_threshold' => 'integer',
            'sort_order' => 'integer',
            'rate_type' => CommissionRateType::class,
            'rate_value' => 'integer',
            'is_breakaway_rank' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<User, $this> Agents currently holding this rank. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'current_rank_id');
    }
}
