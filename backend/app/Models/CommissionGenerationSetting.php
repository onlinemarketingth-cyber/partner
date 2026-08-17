<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-011 Section 3c (TASK-031) — one row per company: max_generation_depth
 * caps how far GenerationCommissionService::payGenerationOverrides() walks
 * (BR-7, ag-lead-added config field — see migration's own comment).
 */
class CommissionGenerationSetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'max_generation_depth',
    ];

    protected function casts(): array
    {
        return [
            'max_generation_depth' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
