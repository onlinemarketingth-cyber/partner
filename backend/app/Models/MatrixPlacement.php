<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-011 Section 3b (TASK-030) — one row per agent placed into a
 * Matrix-plan company's tree. parent_id is the ACTUAL placement parent
 * (may differ from the agent's users.manager_id/sponsor if spillover
 * occurred — see MatrixPlacementService::place()'s own docblock).
 */
class MatrixPlacement extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'parent_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
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

    /** @return BelongsTo<User, $this> The agent whose SLOT this placement occupies (null = root of the company's matrix). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }
}
