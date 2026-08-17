<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Services\Academy\LessonAccessGate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Academy — one row per Agent per lesson. Append-only. Unique on
 * (user_id, module_lesson_id).
 *
 * ADR-009 (2026-07-22) — retargeted from module_id to
 * module_lesson_id: completion is now tracked at the Lesson level (a
 * Section/Module is "done" once all its lessons are), matching the
 * Udemy-style Section->Lesson hierarchy. For a content_type=quiz
 * lesson, `score` holds the quiz result — the quiz is formative,
 * submitting it (any score) is what creates this row, same "mark
 * complete" mechanism as a video/pdf/link lesson.
 */
class ModuleCompletion extends Model
{
    use HasFactory;

    public $timestamps = false; // created_at only, set via DB default

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        /*
         * ADR-031 §2.2 — LessonAccessGate memoises the sequential chain, which
         * is derived from THIS table. Writing a row invalidates that memo.
         *
         * The hook lives on the model, not on ModuleCompletionService, because
         * the Service is only ONE way a row gets written. A seeder, a console
         * command, a data migration, or a test fixture calling
         * ModuleCompletion::create() directly all bypass it — and the first
         * attempt at this fix did exactly that and still failed, because the
         * memo had already been populated by an earlier request in the same
         * process. The model is the one place every write must pass through.
         *
         * Why this matters outside tests: the memo only outlives a request
         * where `scoped()` is not flushed per request — Octane flushes it, but
         * a queue worker does not, and nothing flushes it WITHIN a request. On
         * a sequential Section that meant a learner who had just earned the
         * next lesson could still be told they had not.
         *
         * Resolved from the container rather than injected: the gate is bound
         * `scoped()`, so this reaches the same instance the request is using,
         * and a model event cannot take constructor dependencies anyway.
         */
        static::created(function (self $completion): void {
            // withoutGlobalScopes: a completion can be written by a console
            // command or a seeder with nobody authenticated, and TenantScope
            // would then resolve against no user. The id came from a row the
            // caller already had, so this is a lookup, not an authorisation.
            $moduleId = ModuleLesson::withoutGlobalScopes()
                ->whereKey($completion->module_lesson_id)
                ->value('module_id');

            if ($moduleId) {
                app(LessonAccessGate::class)->forgetChain((int) $moduleId, (int) $completion->user_id);
            }
        });
    }

    protected $fillable = [
        'company_id',
        'user_id',
        'module_lesson_id',
        'completed_at',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'score' => 'integer',
            'created_at' => 'datetime',
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
