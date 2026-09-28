<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 2026-09-27 — ADR-048. Platform-wide supplier settings: a single row.
 *
 * `auto_receive_days` — days after a supplier marks an order shipped before
 * the system confirms receipt on the recipient's behalf. Seeded at 15 by its
 * migration (the owner's number), edited on จัดการคู่ค้า. See
 * SupplierPlatformSettingService.
 */
class SupplierPlatformSetting extends Model
{
    protected $fillable = [
        'auto_receive_days',
    ];

    protected function casts(): array
    {
        return [
            'auto_receive_days' => 'integer',
        ];
    }
}
