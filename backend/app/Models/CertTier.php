<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Academy — CLAUDE.md §2 (Cert Tier), BR-1. Global/platform-wide, no
 * TenantScope (see ERD-001 open question #2 — proposed default).
 */
class CertTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'sort_order',
        'is_mandatory',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_mandatory' => 'boolean',
        ];
    }

    /** @return HasMany<Module, $this> */
    public function modules(): HasMany
    {
        return $this->hasMany(Module::class);
    }

    /** @return HasMany<Exam, $this> */
    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }
}
