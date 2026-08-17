<?php

namespace App\Models;

use App\Enums\GamificationSourceType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-042 §1 — Reward Points, decoupled from XP (BR-5). Agent's
 * available points = SUM(points_awarded) minus reserved/spent points
 * (see RewardRedemptionService::calculateAvailablePoints()). Never
 * stored/duplicated on the user row. Append-only — mirrors xp_ledger's
 * shape and discipline 1:1 (see that model's own docblock), plus a
 * nullable back-reference to the originating xp_ledger row for
 * traceability.
 */
class RewardPointLedger extends Model
{
    use HasFactory;

    // Migration creates a singular "reward_point_ledger" table
    // (matching xp_ledger/commission_ledger's singular convention) —
    // Eloquent's default pluralization would otherwise look for
    // "reward_point_ledgers" and fail with "no such table".
    protected $table = 'reward_point_ledger';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'source_type',
        'source_id',
        'points_awarded',
        'xp_ledger_id',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => GamificationSourceType::class,
            'source_id' => 'integer',
            'points_awarded' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<XpLedger, $this> */
    public function xpLedger(): BelongsTo
    {
        return $this->belongsTo(XpLedger::class, 'xp_ledger_id');
    }
}
