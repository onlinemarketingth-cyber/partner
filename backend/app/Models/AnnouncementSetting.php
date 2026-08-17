<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-076 — BR-7: how many times the Agent Portal auto-pops an unseen
 * announcement before it stops, admin-editable per company. One optional
 * row per company (unique company_id) — see
 * AnnouncementSettingService::forCompany() for the config/announcements.php
 * platform-default fallback used when a company has no row.
 */
class AnnouncementSetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'repeat_count',
        'display_style',
    ];

    protected function casts(): array
    {
        return [
            'repeat_count' => 'integer',
            'display_style' => \App\Enums\AnnouncementDisplayStyle::class,
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
