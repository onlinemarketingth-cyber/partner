<?php

namespace App\Models;

use App\Enums\TeamVisibilityLevel;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-106 / ADR-024 §5 — BR-7: how much of a subordinate's client data a
 * team leader may see, admin-editable per company. One optional row per
 * company (unique company_id) — see
 * App\Services\Sales\TeamVisibilitySettingService::forCompany() for the
 * fail-closed fallback used when a company has no row.
 *
 * NEVER read this table directly anywhere else: go through
 * TeamVisibilitySettingService (for the admin CRUD) or
 * DownlineService::resolveLevel() (for enforcement), so the fail-closed
 * "missing row / is_enabled = false => counts_only" rule can never be
 * forgotten at a call site.
 */
class TeamVisibilitySetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'client_visibility_level',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'client_visibility_level' => TeamVisibilityLevel::class,
            'is_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
