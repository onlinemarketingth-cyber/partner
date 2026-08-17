<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-006 Round 4 — running left/right sales-volume balance per agent
 * under a Binary plan. Schema only for now — no Service writes/reads
 * this yet (Binary is "under development").
 */
class BinaryLegVolume extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'agent_id',
        'left_volume_satang',
        'right_volume_satang',
        'last_cycle_at',
    ];

    protected function casts(): array
    {
        return [
            'left_volume_satang' => 'integer',
            'right_volume_satang' => 'integer',
            'last_cycle_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
