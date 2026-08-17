<?php

namespace App\Models;

use App\Enums\GamificationSourceType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-5 — Agent's total XP = SUM(xp_awarded), never stored/duplicated on
 * the user row. Append-only.
 */
class XpLedger extends Model
{
    use HasFactory;

    // Migration creates a singular "xp_ledger" table (matching
    // commission_ledger's singular convention — see that migration's
    // own comment) — Eloquent's default pluralization would otherwise
    // look for "xp_ledgers" and fail with "no such table".
    protected $table = 'xp_ledger';

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
        'xp_awarded',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => GamificationSourceType::class,
            'source_id' => 'integer',
            'xp_awarded' => 'integer',
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
}
