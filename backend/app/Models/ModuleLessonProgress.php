<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-028 §2.3 — verified learning progress. One row per user per lesson.
 *
 * `max_*` is the gate; `last_*` is only for resume. Both are written
 * exclusively by ModuleLessonProgressService, which enforces the monotonic
 * rule and clamps forged positions — never write these columns from a
 * Controller or a Resource.
 *
 * Deliberately NOT visible to the learner: ADR-028 §4 (human decision,
 * 2026-08-08) says a blocked learner is told what to do, never how far
 * they got. The recorded numbers are exposed on the ADMIN-only readout
 * (GET /module-lessons/{lesson}/progress) so support can resolve the
 * disputes that decision guarantees will arrive.
 */
class ModuleLessonProgress extends Model
{
    use HasFactory;

    // Laravel would pluralise this to module_lesson_progresses; the table
    // is named for the mass noun, as the TASK-146 spec requires.
    protected $table = 'module_lesson_progress';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'module_lesson_id',
        'last_position_seconds',
        'max_position_seconds',
        'last_page',
        'max_page',
        'total_pages',
    ];

    protected function casts(): array
    {
        return [
            'last_position_seconds' => 'integer',
            'max_position_seconds' => 'integer',
            'last_page' => 'integer',
            'max_page' => 'integer',
            'total_pages' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ModuleLesson, $this> */
    public function moduleLesson(): BelongsTo
    {
        return $this->belongsTo(ModuleLesson::class);
    }
}
