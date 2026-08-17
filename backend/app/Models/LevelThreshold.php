<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Gamification — BR-5, BR-7. Global config, no TenantScope.
 */
class LevelThreshold extends Model
{
    use HasFactory;

    protected $fillable = [
        'level_number',
        'xp_required',
    ];

    protected function casts(): array
    {
        return [
            'level_number' => 'integer',
            'xp_required' => 'integer',
        ];
    }
}
