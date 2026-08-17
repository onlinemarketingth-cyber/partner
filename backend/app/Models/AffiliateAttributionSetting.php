<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-011 Section 4 (TASK-032) — one row per company: attribution
 * window (BR-7) for AffiliateLeadCaptureService. new_vs_returning_rate_differential_enabled
 * is a reserved flag only (no differential calculation exists yet —
 * see migration's own comment).
 */
class AffiliateAttributionSetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'attribution_window_days',
        'new_vs_returning_rate_differential_enabled',
    ];

    protected function casts(): array
    {
        return [
            'attribution_window_days' => 'integer',
            'new_vs_returning_rate_differential_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
