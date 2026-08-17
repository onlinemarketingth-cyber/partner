<?php

namespace App\Models;

use App\Enums\GamificationSourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-5 config. company_id nullable = platform default; a per-company row
 * overrides it. Deliberately NOT TenantScope'd — a blanket company_id
 * filter would incorrectly hide platform-default (null) rows for
 * tenant-scoped users. Composing "company override OR platform default"
 * is a Service-layer concern (a future task), not this model.
 */
class GamificationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'source_type',
        'xp_value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => GamificationSourceType::class,
            'xp_value' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
