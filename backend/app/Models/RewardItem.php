<?php

namespace App\Models;

use App\Enums\RewardType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agent-view IA item 1.5 — reward catalog. company_id nullable = own
 * company override or platform-wide default, same shape as Badge. NOT
 * TenantScope'd for the same reason Badge/GamificationRule aren't — see
 * those models' own comments (index() narrows manually so the
 * "company_id OR null" OR-condition isn't clobbered by a global scope
 * that would otherwise force company_id = own company only).
 */
class RewardItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'cost_points',
        'stock_quantity',
        'is_active',
        'reward_type',
    ];

    protected function casts(): array
    {
        return [
            'cost_points' => 'integer',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
            'reward_type' => RewardType::class,
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
