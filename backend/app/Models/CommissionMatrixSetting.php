<?php

namespace App\Models;

use App\Enums\MatrixSpilloverRule;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-011 Section 3b (TASK-030) — one row per company, only relevant
 * when the company (or an individual product, TASK-027) is on
 * commission_plan_type = matrix.
 */
class CommissionMatrixSetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'width',
        'depth',
        'spillover_rule',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'depth' => 'integer',
            'spillover_rule' => MatrixSpilloverRule::class,
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
