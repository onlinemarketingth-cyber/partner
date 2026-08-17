<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-5. condition_config (ERD-001 open question #9) is now read by
 * BadgeConditionEvaluator (Phase 10) when non-null — a whitelisted
 * array-of-conditions format, validated at authoring time by
 * Store/UpdateBadgeRequest. Null means manual-award-only, same as
 * before Phase 10. company_id nullable = platform default — same
 * "not TenantScope'd" note as GamificationRule.
 */
class Badge extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'key',
        'name',
        'description',
        'icon',
        'condition_config',
    ];

    protected function casts(): array
    {
        return [
            'condition_config' => 'array',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
