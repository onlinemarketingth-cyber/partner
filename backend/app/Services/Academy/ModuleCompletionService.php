<?php

namespace App\Services\Academy;

use App\Enums\GamificationSourceType;
use App\Models\AuditLog;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\User;
use App\Services\Gamification\GamificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// BR-5 (XP source (a): "completing learning modules"). ADR-009 —
// completion is tracked per Lesson now, not per Section; a
// content_type=quiz lesson is "completed" by submitting its quiz (any
// score — the quiz is formative, never a BR-1 gate), same call path as
// a video/pdf/link lesson's plain mark-complete button.
//
// TASK-146 / ADR-028 §2.3 — completion is now EARNED, not asserted. This
// class used to write a row on any POST, which meant an agent could
// complete a 40-minute video without opening it and then pass the BR-1
// Basic gate on the strength of it (ADR-028 §1). LessonCompletionGate now
// stands in front of the write.
class ModuleCompletionService
{
    public function __construct(
        private GamificationService $gamificationService,
        private LessonCompletionGate $gate,
        // TASK-151 / ADR-031 §2.2 — a DIFFERENT question from $gate's: not
        // "did they earn it" but "were they allowed to open it at all".
        private LessonAccessGate $access,
    ) {}

    public function complete(ModuleLesson $lesson, User $agent, ?int $score): ModuleCompletion
    {
        if ($lesson->company_id !== $agent->company_id) {
            // Defense-in-depth beyond the Form Request's tenant-scoped
            // `exists` rule — never let a completion get attached to a
            // lesson outside the agent's own company (BR-6).
            throw ValidationException::withMessages(['module_lesson_id' => 'This lesson does not belong to your company.']);
        }

        $existing = $this->existingCompletion($lesson, $agent);

        if ($existing) {
            /*
             * ADR-028 §2.3 guard 1 / ADR-029 §3 — GRANDFATHERING.
             *
             * An existing completion is NEVER re-evaluated against the
             * current rules. Every row written before this sprint was
             * earned under the rule of its day (a button press before
             * ADR-028; content-only before ADR-029), and a repeat POST for
             * a lesson already finished must stay the no-op it has always
             * been.
             *
             * This is deliberately the FIRST thing that happens after the
             * tenant check and BEFORE the gate: nobody loses a
             * certification because we tightened the rule afterwards. That
             * covers ADR-029's addition too — an admin who adds a blocking
             * quiz to a lesson today cannot un-complete the learners who
             * finished it yesterday.
             */
            return $existing;
        }

        /*
         * TASK-151 / ADR-031 §2.2 — "a locked lesson's ... completion must
         * be refused. A client-side lock is decoration, and this one is on
         * the BR-1 path."
         *
         * Placed AFTER the grandfathering early-return above and BEFORE the
         * content gate, and both positions matter:
         *
         *  - after grandfathering, because switching `enforce_sequential`
         *    on today must not make yesterday's completions unreachable
         *    (they are already recorded; a repeat POST stays the no-op it
         *    has always been). Same guarantee ADR-028 §2.3 guard 1 and
         *    ADR-029 §3 give.
         *  - before the content gate, because "you may not open this yet" is
         *    the truer answer than "you have not watched enough of it", and
         *    a learner told the latter would go and try to watch something
         *    the stream route is already refusing them.
         */
        $lockReason = $this->access->reasonFor($lesson, $agent);

        if ($lockReason !== null) {
            throw ValidationException::withMessages([
                'module_lesson_id' => $lockReason->message(),
            ]);
        }

        if (! $this->gate->isEarned($lesson, $agent)) {
            throw ValidationException::withMessages([
                // ADR-028 §4 — actionable, never specific. Neither the
                // recorded progress figure nor (ADR-029) the pass
                // percentage or the learner's own score appears in ANY
                // field of this response.
                'module_lesson_id' => $this->gate->blockedMessage($lesson, $agent),
            ]);
        }

        // Earned the hard way — BR-5 source (a) applies in full.
        return $this->record($lesson, $agent, $score, awardXp: true);
    }

    /**
     * TASK-165 §3.2/§3.3 — THE AUTOMATIC PATH. "Where the system can
     * measure, it records."
     *
     * Called after the two events that can newly satisfy the gate without
     * anyone pressing anything: a progress ping
     * (ModuleLessonProgressService::record) and a graded quiz attempt
     * (ModuleLessonQuizAttemptService::attempt). §3.3 is the one that gets
     * forgotten — a quiz-blocked lesson is read to 100% (no completion,
     * correctly, the quiz is unmet), the learner then passes the quiz, and
     * NO further progress ping ever arrives because there is nothing left
     * to read. Without a trigger there they are stuck incomplete having
     * done everything asked.
     *
     * @return bool whether this lesson is complete for this learner NOW —
     *              true also when it already was (the caller uses it to
     *              flip a row, and an idempotent answer means a client that
     *              lost the earlier response recovers on the next ping)
     *
     * TWO PROPERTIES, both deliberate:
     *
     * 1. **It delegates the write to complete().** Not
     *    ModuleCompletion::create() — that would drop the tenant check, the
     *    ADR-031 lock check, the ADR-028 §2.3 grandfathering early-return
     *    and the BR-5 XP award in one go. XP stays `awardXp: true` inside:
     *    this IS an earned completion (the gate said so), not the ADR-028
     *    §4.1 admin override.
     *
     * 2. **It never throws.** complete() answers a refusal with a 422,
     *    which is right when a learner PRESSED something and wrong here —
     *    nobody pressed anything, and a progress ping that 422s because the
     *    learner is only 40% through a video would turn every normal report
     *    into an error (TASK-165 §3.5: "do not surface a gate refusal for
     *    an automatic lesson"). So every condition complete() would throw
     *    on is asked first, as a question. complete() then re-asks them all
     *    — that is defense in depth, not a second implementation: both
     *    calls go through the same gate objects.
     */
    public function completeIfEarned(ModuleLesson $lesson, User $agent): bool
    {
        if ($this->existingCompletion($lesson, $agent) !== null) {
            // Already complete. Asked FIRST, so this is also the
            // grandfathering answer: a row earned under an older rule is
            // reported as complete and never re-evaluated.
            return true;
        }

        if (! $this->gate->isMeasurable($lesson)) {
            /*
             * TASK-165 §2 — this lesson is on the BUTTON side of the split.
             * Auto-completing it would mean recording a completion off a
             * position report we have already decided measures nothing (a
             * downloadable file, somebody else's embed, an unprobed video),
             * i.e. exactly the asserted completion ADR-028 §1 removed.
             */
            return false;
        }

        if ($lesson->company_id !== $agent->company_id) {
            // BR-6. Unreachable through either caller (both are behind
            // TenantScope'd route-model binding), and stated anyway so this
            // method is safe for a future caller that is not.
            return false;
        }

        // ADR-031 §2.2 — a locked lesson records no completion, however
        // much progress reached the server before the lock applied.
        if ($this->access->reasonFor($lesson, $agent) !== null) {
            return false;
        }

        if (! $this->gate->isEarned($lesson, $agent)) {
            // The normal answer, several times a minute, for a learner
            // partway through. Not an error; just "not yet".
            return false;
        }

        $this->complete($lesson, $agent, null);

        return true;
    }

    /**
     * ADR-028 §2.3 guard 2 — a Company Admin / Super Admin marks a lesson
     * complete FOR an agent, bypassing the gate.
     *
     * "Files fail to render, devices break, a learner reads a printout. A
     * rule with no override becomes a support queue." ADR-028 §4 goes
     * further and predicts the support contacts explicitly — this is the
     * pressure valve, and §5 says make it discoverable BEFORE rollout.
     *
     * ADR-029 §3 requires it to cover a QUIZ-BLOCKED lesson too ("Support
     * load rises where quiz_blocks_completion is on... it must cover
     * quiz-blocked lessons too"). It does, by construction: this path never
     * consults LessonCompletionGate at all, so extending that gate with the
     * quiz condition cannot narrow the override.
     *
     * TASK-151 / ADR-031 §2.2 requires the SAME of a SEQUENTIALLY LOCKED
     * lesson, and for a sharper reason: sequential unlock was accepted with
     * its cost stated plainly — "one lesson whose content is broken blocks
     * everyone behind it" — and "the ADR-028 admin override is the release
     * valve, and it must be reachable from the progress readout before this
     * ships". So this method deliberately does NOT consult LessonAccessGate
     * either. An admin can unstick a learner stranded behind a broken
     * lesson by completing that lesson FOR them, which also unlocks the rest
     * of the chain, because the chain reads module_completions and this
     * writes one. If a future edit adds an access check here, ADR-031 §2.2
     * loses the only escape it was granted on.
     *
     * Audit-logged per CLAUDE.md §6: it affects certification, because a
     * completed lesson feeds the BR-1 Basic gate that unlocks selling
     * rights. Authorization lives in the Form Request
     * (StoreModuleCompletionOverrideRequest) against ModulePolicy::update.
     */
    public function overrideComplete(ModuleLesson $lesson, User $target, User $actor): ModuleCompletion
    {
        if ($lesson->company_id !== $target->company_id) {
            // BR-6 defense-in-depth, mirroring complete() above — the
            // Request already scopes the user_id `exists` rule to the
            // lesson's company, this makes a future caller unable to
            // sidestep it.
            throw ValidationException::withMessages(['user_id' => 'This agent does not belong to the same company as the lesson.']);
        }

        return DB::transaction(function () use ($lesson, $target, $actor) {
            $existing = $this->existingCompletion($lesson, $target);

            if ($existing) {
                // Idempotent, same reasoning as ManualCertificationService
                // (TASK-058): re-clicking "mark complete" on an
                // already-complete lesson must never duplicate the row or
                // the audit trail.
                return $existing;
            }

            // ADR-028 §4.1 — AN OVERRIDE AWARDS NO XP. The completion row
            // is written and audit-logged exactly as before; only the
            // gamification award is withheld. See record()'s $awardXp
            // parameter for the reasoning.
            $completion = $this->record($lesson, $target, null, awardXp: false);

            AuditLog::create([
                'company_id' => $lesson->company_id,
                'actor_user_id' => $actor->id,
                'action' => 'module_completion.admin_override',
                'auditable_type' => ModuleCompletion::class,
                'auditable_id' => $completion->id,
                'old_values' => null,
                'new_values' => [
                    'user_id' => $target->id,
                    'module_lesson_id' => $lesson->id,
                    'completed_at' => $completion->completed_at?->toIso8601String(),
                ],
                'ip_address' => request()?->ip(),
            ]);

            return $completion;
        });
    }

    private function existingCompletion(ModuleLesson $lesson, User $agent): ?ModuleCompletion
    {
        // withoutGlobalScopes + explicit server-resolved keys: the admin
        // override path runs as a DIFFERENT user than $agent, and for a
        // Super Admin TenantScope would not filter at all. Both keys are
        // already authorized by the caller, so this narrows rather than
        // widens.
        return ModuleCompletion::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('module_lesson_id', $lesson->id)
            ->first();
    }

    /**
     * The single write path, shared by the earned and the overridden case
     * so the XP rule below can only ever be expressed once.
     *
     * @param  bool  $awardXp  ADR-028 §4.1 — false ONLY for the admin
     *                         override. Named, not defaulted: a future
     *                         caller has to state which kind of completion
     *                         it is writing, rather than inherit an
     *                         answer it never thought about.
     */
    private function record(ModuleLesson $lesson, User $agent, ?int $score, bool $awardXp): ModuleCompletion
    {
        $completion = ModuleCompletion::withoutGlobalScopes()->firstOrCreate(
            ['user_id' => $agent->id, 'module_lesson_id' => $lesson->id],
            ['company_id' => $agent->company_id, 'score' => $score, 'completed_at' => now()],
        );

        /*
         * ADR-031 §2.2 — the sequential chain is derived from module_completions,
         * and LessonAccessGate memoises it. We have just changed the thing it
         * memoised, so the memo is now a lie: the next lesson is unlocked and
         * the cached answer still says it is not.
         *
         * Found by the human's first real test run (two failures reading
         * "ต้องเรียนบทก่อนหน้าให้จบก่อน" immediately after completing the
         * predecessor). The memo survives across requests wherever `scoped()`
         * is not flushed per request — a feature test, a queue worker — and
         * across a single request regardless.
         *
         * Invalidate at the write, not at every read: this is the ONLY place a
         * completion is created, so it is the one place that can know.
         */
        $this->access->forgetChain($lesson->module_id, $agent->id);

        /*
         * TWO INDEPENDENT CONDITIONS, both of which must hold. They are
         * deliberately kept as one expression so neither can be satisfied
         * by accident:
         *
         * 1. `wasRecentlyCreated` — BR-5 XP is awarded ONCE per lesson, on
         *    the genuine first completion. It is false when firstOrCreate()
         *    returned an already-existing row (a repeat "complete" call for
         *    a lesson this agent already finished, or a race with a
         *    concurrent request that won). UNCHANGED by ADR-028 §4.1.
         *
         * 2. `$awardXp` — ADR-028 §4.1, ag-lead ruling: AN ADMIN OVERRIDE
         *    AWARDS NO XP. XP rewards learning BEHAVIOUR (BR-5 source (a));
         *    an override records that we are accepting the lesson as done
         *    for an operational reason — a broken file, a device that will
         *    not render it, a learner who read a printout. And XP is not
         *    inert: it feeds levels, badges, the leaderboard, and the
         *    promotion bonuses that pay real money (TASK-042), so awarding
         *    it on override would create a standing incentive to request
         *    one. This makes the path consistent with
         *    ManualCertificationService (TASK-058), which already declined
         *    to award on a manual grant. The learner is credited with the
         *    lesson, not with the effort.
         *
         * Note the ordering consequence, which is intended: an override
         * that lands FIRST consumes the wasRecentlyCreated moment, so a
         * later normal POST for the same lesson returns the existing row
         * and awards nothing. The lesson is already complete; there is no
         * second first completion to reward.
         */
        if ($completion->wasRecentlyCreated && $awardXp) {
            $this->gamificationService->awardXp($agent, GamificationSourceType::ModuleCompleted, $completion->id);
        }

        return $completion;
    }
}
