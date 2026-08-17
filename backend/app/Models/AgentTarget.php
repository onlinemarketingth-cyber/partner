<?php

namespace App\Models;

use App\Enums\TargetMetric;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-053 / ADR-016 — a per-agent, per-period sales/deal/client target,
 * set by an Admin, powering the personal goal ring on the Agent home.
 * Tenant-scoped (§5). target_value is admin data (BR-7), integer satang
 * for the money metric (BR-3).
 */
class AgentTarget extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'agent_id',
        'period',
        'metric',
        'target_value',
    ];

    protected function casts(): array
    {
        return [
            'metric' => TargetMetric::class,
            'target_value' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
