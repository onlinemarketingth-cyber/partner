<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TASK-150 / ADR-030 — a quiz in the LIBRARY: a named bag of questions that
 * an admin can author before any lesson needs it.
 *
 * **One quiz belongs to at most one lesson, forever, until it is explicitly
 * unlinked** (ADR-030 §1). This is a staging area, not a shared bank — the
 * human confirmed the goal is preparation, not reuse. The rule is enforced
 * by the UNIQUE index on `module_lessons.quiz_id`, not by this class; see
 * 2026_08_24_090100's docblock.
 *
 * SoftDeletes because this is authored content (§3), and because §2.4
 * refuses to delete a LINKED quiz at all — so any deleted quiz is by
 * definition one that was sitting unattached in the library.
 */
class Quiz extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected $fillable = [
        'company_id',
        'title',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<ModuleLessonQuizQuestion, $this>
     *
     * ADR-030 §2.1 — the questions moved here from the lesson. Ordered on
     * the relation itself, the same idiom ModuleLesson::quizQuestions() and
     * PipelineTemplate::stages() use, so no caller can accidentally read an
     * unordered question list.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ModuleLessonQuizQuestion::class)->orderBy('sort_order');
    }

    /**
     * The lesson holding this quiz — AT MOST ONE, guaranteed by the UNIQUE
     * index rather than by this being a `hasOne` (a hasOne on a non-unique
     * column would silently pick an arbitrary row).
     *
     * `withTrashed()` deliberately: `module_lessons` is soft-deleted, and a
     * soft-deleted lesson still OCCUPIES the quiz_id as far as the database
     * is concerned. Answering "is this quiz free?" without counting that row
     * would offer the admin a quiz that the UNIQUE index then refuses —
     * exactly the "UI teaches the rule by rejecting the user" failure
     * ADR-030 §2.5 rules out.
     *
     * // TODO: CONFIRM (business rule) — whether soft-deleting a lesson
     * // should RELEASE its quiz back to the library. ADR-030 does not say.
     * // Held as-is (the quiz stays reserved) because that is the lossless
     * // choice: restoring the lesson restores its quiz intact. Ask the
     * // human before changing it — the alternative frees the quiz but makes
     * // a restored lesson come back with no quiz and no explanation.
     *
     * @return HasOne<ModuleLesson, $this>
     */
    public function moduleLesson(): HasOne
    {
        return $this->hasOne(ModuleLesson::class)->withTrashed();
    }

    /**
     * ADR-030 §2.4/§2.5 — is this quiz spoken for?
     *
     * Reads the database directly rather than the loaded relation so a stale
     * in-memory model cannot answer "free" about a quiz someone just took,
     * and `withoutGlobalScopes()` because the answer is a fact about the
     * database, not about who is asking: a Company Admin must not be told a
     * quiz is available because the lesson holding it is invisible to them.
     * (The quiz itself was already tenant-checked to reach this point.)
     */
    public function isAttached(): bool
    {
        return ModuleLesson::withoutGlobalScopes()
            ->withTrashed()
            ->where('quiz_id', $this->id)
            ->exists();
    }
}
