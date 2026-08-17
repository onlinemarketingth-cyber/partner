<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * TASK-196 §2.1 — the single platform-wide commission-rate-cap row.
 * Deliberately NOT TenantScope'd: there is no company_id column at all
 * (see the migration's own docblock for why), and this Model is only
 * ever read/written through PlatformCommissionSettingService, gated by
 * Ability::CommissionRateCapUpdate (Super Admin only) for writes —
 * reads are open to any authenticated user (§2.2, same shape as
 * CertTierController's "must be authenticated" read gate).
 */
class PlatformCommissionSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'max_commission_rate_basis_points',
    ];

    protected function casts(): array
    {
        return [
            'max_commission_rate_basis_points' => 'integer',
        ];
    }
}
