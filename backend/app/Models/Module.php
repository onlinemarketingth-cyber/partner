<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Academy — ERD-001 §"Academy". product_id nullable: teaches about a
 * specific Product, or stays general (onboarding/compliance).
 *
 * ADR-009 (2026-07-22) — a Module is now a "Section" (syllabus chapter):
 * a pure grouping/ordering container under a cert tier. The content
 * item itself (video/pdf/link, or a quiz — see ModuleContentType::Quiz)
 * moved down to ModuleLesson, one Module having many. Pre-existing
 * Module rows were each wrapped into a Section + a single Lesson
 * carrying their old content (see migration
 * 2026_07_22_090300_migrate_modules_to_sections_and_lessons).
 */
class Module extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'cert_tier_id',
        'product_id',
        'title',
        'sort_order',
        'is_published',
        // ADR-031 §2.2 — when true, lesson n is locked until lesson n−1 is
        // complete, WITHIN THIS SECTION ONLY. Default false, so no existing
        // course changes behaviour on deploy. Enforced by LessonAccessGate,
        // not by the client.
        'enforce_sequential',
        // ADR-031 §2.3 — null = available immediately; N = this Section
        // opens N days after the learner's anchor date. See
        // LessonAccessGate::unlocksAt() for the anchor and its
        // `TODO: CONFIRM`.
        'drip_days',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
            'enforce_sequential' => 'boolean',
            'drip_days' => 'integer',
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<ModuleLesson, $this> ADR-009 — the actual content items under this Section.
     *
     * ADR-031 §2.1 — `id` is added as a tiebreak because `sort_order` is not
     * unique. The bulk reorder endpoint rewrites it to 0..n-1, but a Section
     * authored before that (or two lessons created in one request) can tie,
     * and an unstable order would make LessonAccessGate's sequential chain
     * differ between two identical requests.
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(ModuleLesson::class)->orderBy('sort_order')->orderBy('id');
    }
}
