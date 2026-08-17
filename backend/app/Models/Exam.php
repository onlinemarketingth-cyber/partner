<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Academy — ERD-001 open question #6 (exam engine shape, `config` is a
 * placeholder).
 */
class Exam extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'cert_tier_id',
        'title',
        'passing_score',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'passing_score' => 'integer',
            'config' => 'array',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<CertTier, $this> */
    public function certTier(): BelongsTo
    {
        return $this->belongsTo(CertTier::class);
    }

    /** @return HasMany<ExamAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /** @return HasMany<ExamQuestion, $this> Academy Sprint 1 — question bank (replaces the config placeholder). */
    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('sort_order');
    }
}
