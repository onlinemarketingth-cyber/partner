<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-174 (D2) — BR-7: is TASK-026's co-agent commission split live for
 * this company? One optional row per company (unique company_id); a missing
 * row means OFF (see the migration docblock for why off is the default).
 *
 * NEVER read this table directly anywhere else. Every consumer — the
 * calculation (CommissionService), the write endpoints (SetCoAgentRequest /
 * StoreReferralRequest / ReferralController::coAgentOptions) and the read
 * Resources (ReferralResource) — goes through the ONE predicate,
 * App\Services\Commission\CommissionSplitSettingService::isEnabledForCompany().
 * Spec §4: "The single most likely way to get this wrong is six scattered
 * v-ifs that drift, leaving one path that still splits."
 */
class CommissionSplitSetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
