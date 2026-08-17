<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-029 §2.3 — one graded attempt at a lesson's end-of-lesson quiz.
 *
 * APPEND-ONLY, mirroring ExamAttempt (and BR-4's immutable ledger in
 * spirit): rows are created by ModuleLessonQuizAttemptService and never
 * updated or deleted. There is no update()/destroy() route.
 *
 * ADR-029 §2.5 — unlimited retries, so many rows per (user, lesson) is
 * normal and there is no uniqueness constraint. "Every attempt is still
 * recorded, so the admin can see someone who took eleven tries."
 *
 * `score` is a COUNT of correct answers (not a percent — that is where this
 * differs from ExamAttempt), and `passed` is frozen at attempt time so a
 * later change to the admin-editable pass mark (BR-7) cannot retroactively
 * un-pass a learner.
 *
 * The learner's CHOSEN ANSWERS are deliberately not stored — ADR-029 §4
 * item 2 is unresolved and PDPA-adjacent, so: score only until asked.
 */
class ModuleLessonQuizAttempt extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'module_lesson_id',
        'score',
        'total_questions',
        'passed',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'total_questions' => 'integer',
            'passed' => 'boolean',
            'attempted_at' => 'datetime',
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
