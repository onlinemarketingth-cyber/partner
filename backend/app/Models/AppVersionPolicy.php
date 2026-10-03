<?php

namespace App\Models;

use App\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Model;

/**
 * 2026-10-02 — MOB-13. The minimum / latest mobile app version per platform.
 *
 * Deliberately NOT TenantScope'd and has no company_id: one app binary
 * serves every company, so this is platform configuration (see the
 * migration's docblock). Read publicly (the app asks before login) and
 * written only by a Super Admin, both through AppVersionPolicyService.
 */
class AppVersionPolicy extends Model
{
    protected $fillable = [
        'platform',
        'min_supported_version',
        'latest_version',
        'store_url',
    ];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
        ];
    }
}
