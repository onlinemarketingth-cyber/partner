<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-5 — one row per Agent per badge earned. Append-only.
 */
class UserBadge extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'badge_id',
        'earned_at',
    ];

    protected function casts(): array
    {
        return [
            'earned_at' => 'datetime',
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

    /** @return BelongsTo<Badge, $this> */
    public function badge(): BelongsTo
    {
        return $this->belongsTo(Badge::class);
    }
}
