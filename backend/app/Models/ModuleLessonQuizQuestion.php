<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-009 — a formative lesson-quiz question. Deliberately mirrors
 * ExamQuestion exactly (same shape, same mutual-exclusion enforcement
 * in ModuleLessonQuizOptionService) — see that model's docblock for
 * why this is a SEPARATE table from exam_questions rather than a
 * shared/polymorphic one: a lesson quiz never gates BR-1, an Exam
 * always might.
 *
 * ADR-030 §2.1 (TASK-150) — a question now belongs to a **Quiz**, not to a
 * lesson. `module_lesson_id` is gone; the lesson reaches its questions
 * through `module_lessons.quiz_id`. The class name is unchanged on purpose:
 * renaming the table/model would have added a second, larger and entirely
 * cosmetic migration to a change that is already retargeting a foreign key.
 *
 * The whole point of ADR-030 is that a quiz can exist with NO lesson at all
 * (authored in the library, attached later, or never) — so nothing here may
 * assume a lesson exists.
 */
class ModuleLessonQuizQuestion extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        // ADR-030 §2.1 — replaces module_lesson_id.
        'quiz_id',
        'question_text',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quiz, $this>
     *
     * ADR-030 §2.1 — the owner.
     *
     * `withTrashed()` because a quiz is SOFT-deleted (§3: authored content is
     * never destroyed outright) and its questions survive with it. Four
     * authorization paths — the question and option Form Requests, and both
     * destroy() controllers — ask `can('update', $question->quiz)`, and a
     * null parent there would be a 500 on a route that should simply say
     * "no". A deleted quiz is by definition unattached (§2.4 refuses to
     * delete a linked one), so this widens nothing: QuizPolicy still applies
     * the same company check to it.
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class)->withTrashed();
    }

    /** @return HasMany<ModuleLessonQuizOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ModuleLessonQuizOption::class)->orderBy('sort_order');
    }
}
