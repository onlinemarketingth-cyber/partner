<?php

namespace App\Services\Academy;

use App\Enums\LessonLockReason;
use App\Models\Module;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * TASK-151 / ADR-031 §2.2, §2.3, §2.4 — may this learner OPEN this lesson
 * yet?
 *
 * A deliberately separate question from LessonCompletionGate's, and the two
 * must not be merged:
 *
 *   - **LessonCompletionGate** (ADR-028/029) asks "has this learner EARNED
 *     the completion of a lesson they were allowed to open?"
 *   - **LessonAccessGate** (this class, ADR-031) asks "is this lesson open
 *     to them at all yet?"
 *
 * Folding them together would make ADR-031's own rule uncomputable: the
 * sequential chain is defined in terms of the PREVIOUS lesson's completion,
 * which is LessonCompletionGate's output. This class consumes
 * `module_completions` (the recorded result), never re-runs the content
 * gate — the same reason ADR-029 reads `passed` from a stored attempt
 * rather than recomputing it.
 *
 * ===================================================================
 * ENFORCED SERVER-SIDE, ON EVERY ROUTE THAT COULD BYPASS THE UI
 * ===================================================================
 *
 * ADR-031 §2.2: "Enforced server-side. A locked lesson's content must not
 * be streamable and its completion must be refused — a client-side lock is
 * decoration, and this one is on the BR-1 path (§6)."
 *
 * So `is_locked` on the resource is a HINT for rendering, and these four
 * call sites are the actual rule:
 *
 *   1. ModuleLessonController::stream()            → 403, before any bytes
 *   2. ModuleCompletionService::complete()         → 422
 *   3. ModuleLessonProgressService::record()       → 422
 *   4. ModuleLessonQuizAttemptService::attempt()   → 422
 *
 * (4) is not named in the ADR but belongs to the same hole: a `link` or
 * `is_downloadable` lesson satisfies `isContentEarned()` trivially
 * (ADR-028 §2.3), so without it a learner could sit and pass the quiz of a
 * lesson they may not open — and where `quiz_blocks_completion` is on, that
 * is a step on the BR-1 certification path.
 *
 * ===================================================================
 * NOTHING CHANGES FOR EXISTING DATA
 * ===================================================================
 *
 * `modules.enforce_sequential` defaults false and `modules.drip_days`
 * defaults null, so for every Section that exists today `reasonFor()`
 * resolves the parent Section (already eager-loaded on the read paths) and
 * then returns null from two in-memory comparisons: NEITHER the
 * sibling-chain query nor the completion lookup runs.
 *
 * That is ADR-031's non-negotiable 1, expressed in code rather than in a
 * comment — an untouched course cannot behave differently, because the code
 * that could change its behaviour never runs.
 */
class LessonAccessGate
{
    /**
     * Per-request memo, so a Section with 30 lessons costs 2 queries rather
     * than 60. Bound as `scoped()` in AppServiceProvider — WITHOUT that
     * binding the container hands out a fresh instance per `app()` call and
     * this array is dead weight. Keyed by "{moduleId}:{userId}" because the
     * completion half is per learner.
     *
     * @var array<string, array{ordered: Collection<int, ModuleLesson>, completed: array<int, true>}>
     */
    private array $chainMemo = [];

    /** @var array<int, ?Module> */
    private array $moduleMemo = [];

    /**
     * Drop the memoised chain for one learner in one Section.
     *
     * WHY THIS HAD TO EXIST (bug found by the human's first real test run):
     * two tests failed with "ต้องเรียนบทก่อนหน้าให้จบก่อน" on a lesson whose
     * predecessor had just been completed successfully.
     *
     * The memo is the whole cause. `scoped()` bindings are only flushed
     * per-request under Octane; under the normal HTTP kernel, in a queue
     * worker, and in a feature test, one instance outlives many requests. So
     * the chain read during the FIRST completion — when the predecessor was
     * still incomplete — was still being served on the next one.
     *
     * It is not merely a test artefact. Within a SINGLE request,
     * ModuleCompletionService writes a completion and any later consult of
     * this gate would read the pre-write answer. On a sequential Section that
     * means a learner who just earned the next lesson is told they have not.
     *
     * Invalidating on write is the fix rather than deleting the memo: the memo
     * exists because rendering a 30-lesson Section otherwise costs 60 queries,
     * and that is a real cost on the learner's main screen.
     */
    public function forgetChain(int $moduleId, int $learnerId): void
    {
        unset($this->chainMemo[$moduleId.':'.$learnerId]);
    }

    /**
     * The single question every caller asks. Null means "open".
     */
    public function reasonFor(ModuleLesson $lesson, ?User $learner): ?LessonLockReason
    {
        if ($learner === null) {
            // No authenticated learner (there is no unauthenticated Academy
            // route today, so this is a guard rather than a case): a lock is
            // a statement about a PERSON's progress, and with no person
            // there is nothing to lock against.
            return null;
        }

        /*
         * Admins are never locked. They are authoring and previewing, not
         * learning — the same exemption ModuleLessonResource already makes
         * for `quiz_unlocked` (ADR-029 §2.2), and the reason the ADR-028
         * override in §2.3 works at all: an admin who could not open a
         * lesson could not diagnose why a learner is stuck on it.
         */
        if ($learner->isSuperAdmin() || $learner->isCompanyAdmin()) {
            return null;
        }

        $module = $this->moduleFor($lesson);

        if ($module === null) {
            // An orphaned lesson (parent hard-deleted). Fail OPEN rather
            // than bricking it: there is no Section to carry a rule, so
            // there is no rule to enforce.
            return null;
        }

        /*
         * TASK-155 — PUBLISH IS CHECKED BEFORE EVERYTHING ELSE.
         *
         * Before this, nothing on the learner path consulted `is_published` at
         * all: GET /modules applied no filter, and `stream()` would serve a
         * draft lesson's bytes to any agent who guessed its id. Draft lessons
         * were hidden by `visibleLessons()` in the Vue client and nowhere
         * else — precisely the "a client-side lock is decoration" failure
         * ADR-031 §2.2 already rejected for sequential locks, on a route that
         * feeds the BR-1 certification gate.
         *
         * The SECTION's flag counts as much as the lesson's. An admin who
         * unpublishes a Section means its contents are out of the course; if
         * only the lesson flag were read, they would have to unpublish every
         * lesson individually and would reasonably assume they had not.
         *
         * First, ahead of drip and sequence, because it is the broadest
         * statement: drafts are not late or out of order, they are not part of
         * the course. Admins never reach here — the isSuperAdmin/isCompanyAdmin
         * exemption above returns null first, which is what lets them preview
         * what they are drafting.
         */
        if (! $lesson->is_published || ! $module->is_published) {
            return LessonLockReason::NotPublished;
        }

        // ADR-031 §2.3 — drip is checked FIRST because it gates the whole
        // Section. Telling a learner "finish the previous lesson" when the
        // Section is not open yet would send them somewhere they also
        // cannot go.
        if ($this->isDripped($module, $learner)) {
            return LessonLockReason::Drip;
        }

        if ($this->isSequentiallyLocked($module, $lesson, $learner)) {
            return LessonLockReason::SequentialPrevious;
        }

        return null;
    }

    public function isLocked(ModuleLesson $lesson, ?User $learner): bool
    {
        return $this->reasonFor($lesson, $learner) !== null;
    }

    /**
     * ADR-031 §2.3 — WHEN this Section opens for this learner, so the UI can
     * say "เปิดในอีก 3 วัน" rather than an unexplained padlock (§3).
     *
     * Returns null when the Section has no drip configured. Returns a
     * timestamp in the PAST once the wait is over — deliberately, so a
     * client can render "เปิดแล้วเมื่อ ..." without a second field to
     * distinguish "never dripped" from "dripped and open".
     *
     * // TODO: CONFIRM (business rule) — ADR-031 §4 item 1: THE ANCHOR.
     * //
     * // Implemented as the learner's account approval date
     * // (`users.approved_at`, falling back to `created_at` for accounts
     * // that predate the approvals feature), because it is the only date
     * // every learner definitely has and "7 days after you joined" is a
     * // sentence a company can explain to an agent (ADR-031 §2.3).
     * //
     * // If the human meant "7 days after they OPEN THE COURSE", that needs
     * // a first-touch timestamp this system does not record. It cannot be
     * // derived from module_lesson_progress either — that row is written
     * // only for video/pdf lessons, so a Section of link lessons would
     * // never anchor. It is a schema decision (a new column, and a
     * // backfill answer for everyone already mid-course), not a tweak
     * // here. ag-lead: this is the one open value in TASK-151.
     */
    public function unlocksAt(Module $module, ?User $learner): ?Carbon
    {
        if ($module->drip_days === null || $learner === null) {
            return null;
        }

        $anchor = $learner->approved_at ?? $learner->created_at;

        if ($anchor === null) {
            // A user row with neither date should not exist (created_at is
            // written by Eloquent), but a null anchor would otherwise mean
            // "unlocks at an unknown time", i.e. a permanent lockout from a
            // missing timestamp. Fail open and say so.
            return null;
        }

        // ->copy() is load-bearing: Illuminate\Support\Carbon is MUTABLE, so
        // addDays() on the model's own attribute would silently move the
        // learner's approved_at for the rest of the request.
        return $anchor->copy()->addDays((int) $module->drip_days);
    }

    /**
     * The same answer keyed by a LESSON, for ModuleLessonResource — which
     * has the lesson but not its parent to hand.
     */
    public function unlocksAtForLesson(ModuleLesson $lesson, ?User $learner): ?Carbon
    {
        $module = $this->moduleFor($lesson);

        return $module === null ? null : $this->unlocksAt($module, $learner);
    }

    private function isDripped(Module $module, User $learner): bool
    {
        $unlocksAt = $this->unlocksAt($module, $learner);

        return $unlocksAt !== null && $unlocksAt->isFuture();
    }

    /**
     * ADR-031 §2.2 — "lesson n is locked until lesson n−1 is COMPLETE", and
     * §2.4 — "an optional lesson never blocks a sequential chain".
     *
     * Both rules live in the one backwards walk below. Reading it as a
     * sentence: *find the nearest earlier lesson that is actually required
     * of this learner, and ask whether they finished it.*
     */
    private function isSequentiallyLocked(Module $module, ModuleLesson $lesson, User $learner): bool
    {
        if (! $module->enforce_sequential) {
            return false;
        }

        $chain = $this->chain($module, $learner);
        $index = $chain['ordered']->search(fn (ModuleLesson $l) => $l->id === $lesson->id);

        if ($index === false || $index === 0) {
            // Not in the chain (unpublished — see chain()) or first in it.
            // The first lesson of a sequential Section is always open, or
            // the Section would be unenterable.
            return false;
        }

        for ($i = $index - 1; $i >= 0; $i--) {
            /** @var ModuleLesson $previous */
            $previous = $chain['ordered'][$i];

            if ($previous->is_optional) {
                // ADR-031 §2.4 — walk PAST it. An optional lesson sitting
                // between two required ones must not gate the next required
                // one; if it did, "optional" would mean "required, but we
                // called it something else".
                continue;
            }

            return ! isset($chain['completed'][$previous->id]);
        }

        // Everything before this lesson is optional — nothing required to
        // have finished, so nothing to block on.
        return false;
    }

    /**
     * The ordered sibling set + which of them this learner has completed.
     *
     * PUBLISHED ONLY. An unpublished lesson is invisible to the learner, so
     * it can never be completed — leaving one in the chain would produce a
     * permanent lockout with no cause the admin could see, which is exactly
     * the failure mode ADR-031 §2.2 already flags ("one lesson whose content
     * is broken blocks everyone behind it") multiplied by an invisible row.
     *
     * Ordered by (sort_order, id): sort_order is not unique — the bulk
     * reorder endpoint rewrites it to 0..n-1, but a Section authored before
     * that, or two rows created in the same request, can tie. `id` as the
     * tiebreak makes the chain deterministic instead of dependent on the
     * database's row order, which would make a lock appear and disappear
     * between requests.
     *
     * `withoutGlobalScopes` + fully server-resolved keys, for the same
     * reason LessonCompletionGate gives: both keys come from records the
     * caller already authorized, and TenantScope here would be evaluated
     * against whoever is authenticated rather than against the lesson.
     *
     * @return array{ordered: Collection<int, ModuleLesson>, completed: array<int, true>}
     */
    private function chain(Module $module, User $learner): array
    {
        $key = $module->id.':'.$learner->id;

        if (isset($this->chainMemo[$key])) {
            return $this->chainMemo[$key];
        }

        /** @var Collection<int, ModuleLesson> $ordered */
        $ordered = ModuleLesson::withoutGlobalScopes()
            ->where('module_id', $module->id)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'sort_order', 'is_optional'])
            ->values();

        $completed = $ordered->isEmpty()
            ? []
            : ModuleCompletion::withoutGlobalScopes()
                ->where('user_id', $learner->id)
                ->whereIn('module_lesson_id', $ordered->pluck('id'))
                ->pluck('module_lesson_id')
                ->flip()
                ->map(fn () => true)
                ->all();

        return $this->chainMemo[$key] = ['ordered' => $ordered, 'completed' => $completed];
    }

    /**
     * The lesson's parent Section, preferring an already-loaded relation and
     * memoising the lookup otherwise — ModuleLessonResource renders one
     * lesson at a time and would otherwise re-query the same Section for
     * every row.
     */
    private function moduleFor(ModuleLesson $lesson): ?Module
    {
        if ($lesson->relationLoaded('module') && $lesson->module !== null) {
            return $lesson->module;
        }

        if (array_key_exists($lesson->module_id, $this->moduleMemo)) {
            return $this->moduleMemo[$lesson->module_id];
        }

        // withoutGlobalScopes: the lesson is already authorized and its
        // parent is by definition in the lesson's own company, while the
        // ACTOR may be a Super Admin whose TenantScope resolves elsewhere.
        return $this->moduleMemo[$lesson->module_id] = Module::withoutGlobalScopes()
            ->find($lesson->module_id);
    }
}
