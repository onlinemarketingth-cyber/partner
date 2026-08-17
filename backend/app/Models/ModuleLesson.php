<?php

namespace App\Models;

use App\Enums\MediaProcessingStatus;
use App\Enums\MediaSourceType;
use App\Enums\ModuleContentType;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ADR-009 (2026-07-22) — the actual content item within a Module
 * ("Section"): video/pdf/link, or a quiz (content_type = quiz, no
 * content_ref — its content lives in quizQuestions()). Was previously
 * the Module row itself before the Section/Lesson split.
 */
class ModuleLesson extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'module_id',
        'title',
        'content_type',
        // ADR-007 — only meaningful when content_type = video: decides
        // whether content_ref holds an embed URL or our own private-disk
        // file_path. Null for pdf/link/quiz.
        'source_type',
        'content_ref',
        'processing_status',
        // ADR-028 §2.2 — per-file admin choice. NOT DRM (see the
        // migration's docblock); when true, ADR-028 §2.3 makes this
        // lesson's completion fall back to the plain button, because a
        // file the learner may keep can be read outside the app.
        'is_downloadable',
        // ADR-028 §2.3 — server-derived (CompressUploadedVideo + ffprobe),
        // never client-supplied. Deliberately absent from the Form
        // Requests: see the migration docblock.
        'duration_seconds',
        // ADR-028 §2.3 — server-measured (pdfinfo) PDF page count, the
        // un-forgeable denominator of the PDF gate. Server-derived only,
        // same reasoning as duration_seconds above.
        'page_count',
        // ADR-029 §2.4 — NULL means inherit from
        // academy_completion_settings.quiz_pass_percent (BR-7: the pass mark
        // is admin-editable config at both levels, never a constant).
        'quiz_pass_percent',
        // ADR-029 §2.6 — when true this lesson is not complete until its
        // quiz is passed, so BR-1's certification path runs through it.
        'quiz_blocks_completion',
        'sort_order',
        'xp_reward',
        'is_published',
        // ADR-031 §2.4 — "shown, not counted". Excluded from every progress
        // denominator, and skipped when LessonAccessGate walks the
        // sequential chain, so an optional lesson can never gate the next
        // required one. Default false: every lesson that exists today is
        // required, exactly as it is today.
        'is_optional',
        /*
         * ADR-030 §2.1 — `quiz_id` is DELIBERATELY ABSENT from this list.
         *
         * The column carries an exclusivity rule enforced by a UNIQUE index,
         * and letting it ride along in a lesson create/update payload would
         * mean an admin could take another lesson's quiz — or hit a raw
         * driver error instead of a 422 — through a form that says nothing
         * about quizzes. It may only move through QuizService::attach() /
         * detach(), which check §2.4, §2.5 and BR-6 first and write an audit
         * entry (CLAUDE.md §6: a change that can switch a completion gate on
         * the BR-1 path off is exactly what the audit log is for).
         */
    ];

    protected function casts(): array
    {
        return [
            'content_type' => ModuleContentType::class,
            'source_type' => MediaSourceType::class,
            'processing_status' => MediaProcessingStatus::class,
            'is_downloadable' => 'boolean',
            'duration_seconds' => 'integer',
            'page_count' => 'integer',
            'quiz_pass_percent' => 'integer',
            'quiz_blocks_completion' => 'boolean',
            'sort_order' => 'integer',
            'xp_reward' => 'integer',
            'is_published' => 'boolean',
            'is_optional' => 'boolean',
        ];
    }

    /**
     * ADR-028 §2.1 — true when content_ref holds OUR OWN private-disk path
     * rather than an external URL. The single predicate every caller
     * (Resource, stream(), the completion gate) shares, so "is this file
     * ours?" cannot drift between them.
     */
    public function isUploadedFile(): bool
    {
        return $this->source_type === MediaSourceType::Upload
            && $this->content_ref !== null
            && in_array($this->content_type, ModuleContentType::uploadable(), true);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Module, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * @return BelongsTo<Quiz, $this>
     *
     * ADR-030 §2.1 — the lesson's quiz, or null. `quiz_id` is UNIQUE, so no
     * other lesson can be holding the same one.
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * @return HasMany<ModuleLessonQuizQuestion, $this>
     *
     * ADR-029 §2.1 — ANY lesson may carry questions, not only a
     * content_type=quiz one: "a video or PDF lesson gains an end-of-lesson
     * quiz, which is what the human asked for and what the feature was
     * always named after".
     *
     * ADR-030 §2.1 — the questions now live under a Quiz, so this hops the
     * link: `module_lesson_quiz_questions.quiz_id = module_lessons.quiz_id`.
     * Kept as a relation with the SAME NAME and the same ordering rather
     * than pushed onto callers, because ADR-030 §3 names the real risk of
     * this change — "every read path must move with it in the same change;
     * a missed one silently shows an empty quiz". One relation that every
     * reader already uses is the cheapest way to make that impossible.
     *
     * A lesson with `quiz_id = null` yields an EMPTY collection, not every
     * orphan question: Eloquent compiles `where quiz_id = null`, which
     * matches nothing, and the eager-load path drops null keys before
     * building its `whereIn`.
     */
    public function quizQuestions(): HasMany
    {
        return $this->hasMany(ModuleLessonQuizQuestion::class, 'quiz_id', 'quiz_id')
            ->orderBy('sort_order');
    }

    /** @return HasMany<ModuleLessonQuizAttempt, $this> ADR-029 §2.3 — append-only, many per learner (§2.5). */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(ModuleLessonQuizAttempt::class);
    }

    /** @return HasMany<ModuleCompletion, $this> */
    public function completions(): HasMany
    {
        return $this->hasMany(ModuleCompletion::class);
    }

    /** @return HasMany<ModuleLessonProgress, $this> ADR-028 §2.3 — one row per learner. */
    public function progress(): HasMany
    {
        return $this->hasMany(ModuleLessonProgress::class);
    }
}
