<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * TASK-190 §3.1 — the single platform-wide SMTP settings row. Deliberately
 * NOT TenantScope'd: there is no company_id column at all (see the
 * migration's own docblock for why), and this Model is only ever read/
 * written through PlatformMailSettingService, gated by
 * Ability::SettingsMailUpdate (Super Admin only).
 *
 * `password` carries the same 'encrypted' cast as
 * users.bank_account_number (TASK-044) — Laravel transparently
 * encrypts on write / decrypts on read, so PHP call sites see a plain
 * string, but nothing in the DB row is ever plaintext. The API layer
 * (PlatformMailSettingService::get()) still must not just forward that
 * decrypted value — see its own docblock.
 */
class PlatformMailSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'smtp_host',
        'smtp_port',
        'encryption',
        'username',
        'password',
        'from_address',
        'from_name',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            'password' => 'encrypted',
            'is_enabled' => 'boolean',
        ];
    }
}
