<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-007 — BR-7: video compression limits, admin-editable per company.
 * One optional row per company (unique company_id) — see
 * VideoProcessingSettingService::forCompany() for the config/media.php
 * platform-default fallback used when a company has no row.
 */
class VideoProcessingSetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'max_upload_mb',
        'target_resolution',
        'target_bitrate_kbps',
    ];

    protected function casts(): array
    {
        return [
            'max_upload_mb' => 'integer',
            'target_bitrate_kbps' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
