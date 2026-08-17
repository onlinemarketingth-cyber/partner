<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-006 Round 4 — one row per agent per matching-cycle run under a
 * Binary plan. commission_ledger_id links to the resulting payout row
 * (BR-4 immutable ledger entry with earned_via = binary_match); null
 * when a cycle matched zero volume (no ledger row created — never a
 * $0 row, same philosophy as TASK-025's override rule). Schema only
 * for now — no job produces these rows yet (Binary is "under
 * development").
 */
class BinaryMatchingCycle extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'agent_id',
        'period_start',
        'period_end',
        'left_volume_satang',
        'right_volume_satang',
        'matched_volume_satang',
        'unmatched_carried_satang',
        'commission_ledger_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'left_volume_satang' => 'integer',
            'right_volume_satang' => 'integer',
            'matched_volume_satang' => 'integer',
            'unmatched_carried_satang' => 'integer',
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

    /** @return BelongsTo<CommissionLedger, $this> */
    public function commissionLedger(): BelongsTo
    {
        return $this->belongsTo(CommissionLedger::class);
    }
}
