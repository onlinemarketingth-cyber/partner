<?php

namespace App\Services\Referral;

use App\Enums\GamificationSourceType;
use App\Enums\PipelineStage;
use App\Models\PipelineStageLog;
use App\Models\PipelineTemplate;
use App\Models\Referral;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Commission\CommissionService;
use App\Services\Engagement\PromotionBonusService;
use App\Services\Gamification\GamificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * CLAUDE.md Section 4.3 — "Sequential Transitions Only": status changes
 * must be validated against the transitions allowed by THAT REFERRAL'S
 * OWN TEMPLATE (no skipping, no invalid reverse moves), and every change
 * is audit-logged (who, when, from-status -> to-status).
 *
 * ADR-026 §3.6 (TASK-133) — the legal edges moved from the enum onto the
 * template: this Service reads referrals.pipeline_template_id (the
 * snapshot stamped at creation, §3.4) and derives the next stage from
 * that template's ordered stages. The rules are unchanged in spirit —
 * forward-only, no skipping, exactly one legal edge at a time — and BR-4
 * commission still fires at complete_payment and nowhere else.
 *
 * A referral with a NULL snapshot (every pre-ADR-026 row) falls back to
 * PipelineStage::defaultSequence(), i.e. §4.3's original five stages, so
 * legacy rows keep behaving exactly as they did.
 */
class PipelineService
{
    /**
     * Per-instance memo of resolved stage sequences, keyed by template id
     * ('default' for the no-template journey). confirmPayment() asks
     * three questions of the same journey in a row (reached? next?
     * previous?); without this each one re-queries the template and its
     * stages. A journey is immutable for the life of a request — the
     * snapshot is never re-resolved (ADR-026 §3.4) — so memoising it
     * cannot go stale mid-request.
     *
     * @var array<string, list<PipelineStage>>
     */
    private array $sequenceCache = [];

    public function __construct(
        private CommissionService $commissionService,
        private GamificationService $gamificationService,
        private PromotionBonusService $promotionBonusService,
    ) {}

    /**
     * Advance a referral to its one and only allowed next stage.
     *
     * Deliberately takes no "target stage" input from the caller — a
     * referral's journey (its template, or the default sequence) yields
     * exactly one next stage from wherever it currently stands
     * (including OngoingNextMeeting's self-loop), so there is nothing
     * for a client to choose and therefore nothing to validate against a
     * client-supplied value (Section 6 "never trust client input" taken
     * to its safest conclusion: don't accept the input at all when it
     * can't ever be anything but one specific value).
     *
     * ADR-026 §3.6 — the next stage now comes from nextStageFor(), which
     * reads the referral's own template. Refuses (422) rather than
     * silently no-op'ing when the referral has nowhere left to go; see
     * nextStageFor()'s docblock for the last-stage rule.
     *
     * @throws ValidationException when the referral has no legal next stage
     */
    public function advance(Referral $referral, User $actor): Referral
    {
        $fromStage = $referral->current_stage;
        $toStage = $this->nextStageFor($referral);

        // End of the journey. Deliberately a 422, NOT a silent no-op:
        // a no-op would return 200 with an unchanged stage, which reads
        // to an agent (and to the Kanban board) as "it worked" while
        // nothing moved, and would write no audit row for an action the
        // user believes happened (§6 Audit Log). Refusing is also the
        // fail-closed choice: nothing is written, no XP, no commission.
        if (! $toStage) {
            throw ValidationException::withMessages([
                'referral' => 'รายการนี้อยู่ที่ขั้นตอนสุดท้ายของเส้นทางการขายตามแม่แบบของสินค้านี้แล้ว ไม่มีขั้นตอนถัดไป ('.$fromStage->label().')',
            ]);
        }

        return DB::transaction(function () use ($referral, $actor, $fromStage, $toStage) {
            $referral->current_stage = $toStage;

            // "Ongoing Next Meeting (2nd -> 3rd -> 4th)" — this stage
            // self-loops (ADR-026 §3.6: the self-loop is a property of
            // the STAGE, not of the template), so the 2nd/3rd/4th
            // sub-count is tracked via meeting_number instead of
            // separate enum cases. First entry into this stage (from
            // Complete Payment on the medical template) is "the 2nd
            // meeting" — the 1st already happened as its own earlier
            // stage (Finish 1st Doctor Meeting). Every further self-loop
            // advance increments it.
            //
            // TODO: CONFIRM (business rule) — CLAUDE.md §4.3 phrases
            // this as "2nd -> 3rd -> 4th", which reads like a typical
            // range, not a stated hard cap. Not enforced here (no max
            // meeting_number check) — ask a human before adding one, so
            // real usage isn't blocked on a guessed limit.
            if ($toStage === PipelineStage::OngoingNextMeeting) {
                $referral->meeting_number = $fromStage === PipelineStage::OngoingNextMeeting
                    ? $referral->meeting_number + 1
                    : 2;
            }

            $referral->save();

            $stageLog = PipelineStageLog::create([
                'company_id' => $referral->company_id,
                'referral_id' => $referral->id,
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
                'changed_by_user_id' => $actor->id,
                'changed_at' => now(),
            ]);

            // CLAUDE.md §4.3: "Commission (BR-4) triggers at the
            // Complete Payment stage." CommissionService is idempotent
            // (application-level, NOT a DB unique index — that index was
            // dropped by migration 2026_07_14_130000; see its
            // recordForReferral() docblock) and never throws — if commission
            // can't currently be computed (no active commission_rule
            // for this cert tier/product, or the agent somehow has no
            // passed cert tier), it logs a warning and returns null
            // rather than blocking this pipeline advance. A missing
            // rate config is a finance/admin data gap, not a reason to
            // stop recording that a sale actually completed.
            if ($toStage === PipelineStage::CompletePayment) {
                $this->commissionService->recordForReferral($referral);
            }

            // BR-5 (XP source (b): "moving a client through the
            // pipeline"). Credited to the referral's AGENT
            // ($referral->agent), never $actor — a Company Admin
            // advancing a pipeline on an agent's behalf must not steal
            // that agent's sales-activity credit. Every genuine stage
            // transition creates a new, uniquely-id'd PipelineStageLog
            // row (advance() can't be replayed to repeat the exact same
            // transition — current_stage has already moved past it), so
            // awarding XP once per new log row is naturally safe, no
            // extra dedup needed here.
            //
            // Reaching Complete Payment specifically ("closing a sale")
            // gets a SECOND, separately-configured bonus XP event on
            // top of the generic per-stage XP — CLAUDE.md §4.3's
            // "trigger" language for commission at this exact stage
            // reads as this being the meaningful sales-conversion
            // moment, not just another routine stage move.
            //
            // TODO: CONFIRM (business rule) — CLAUDE.md doesn't
            // explicitly say a per-stage XP amount AND a Complete
            // Payment bonus both apply; this is a reasonable reading of
            // GamificationSourceType's own two-tier shape
            // (PipelineStageAdvanced + PaymentComplete as distinct
            // cases), not a guessed number — the actual xp_value for
            // each still comes entirely from gamification_rules.
            //
            // ADR-026 §5 Q1 (human, 2026-08-08): the three post-sale
            // stages earn this ORDINARY per-stage XP like any other
            // transition and get NO separate bonus — the PaymentComplete
            // bonus stays exclusive to complete_payment, which is why the
            // guard below is an === on that one case and not "is at or
            // past payment".
            $this->gamificationService->awardXp($referral->agent, GamificationSourceType::PipelineStageAdvanced, $stageLog->id);
            if ($toStage === PipelineStage::CompletePayment) {
                $this->gamificationService->awardXp($referral->agent, GamificationSourceType::PaymentComplete, $stageLog->id);
            }

            // TASK-042 §3 (BR-7 confirmed 2026-07-23) — third block in
            // this same Complete-Payment guard/transaction, alongside
            // (never replacing) BR-4 commission and bonus XP above:
            // evaluates every active agent_promotions campaign targeting
            // this referral's agent/product and books (and, if
            // payout_timing = immediate, pays) any matching bonus.
            if ($toStage === PipelineStage::CompletePayment) {
                $this->promotionBonusService->evaluateForReferral($referral, $actor);
            }

            return $referral->fresh();
        });
    }

    /**
     * The ONE legal next stage for this referral under its own journey,
     * or null when it has reached the end of that journey.
     *
     * ADR-026 §3.6 — forward-only, no skipping, one legal edge at a
     * time; the edge now comes from the referral's snapshotted template
     * (§3.4) instead of the enum.
     *
     * LAST-STAGE RULE:
     *   - `ongoing_next_meeting` as the final stage SELF-LOOPS, exactly
     *     as it does today (ADR-026 §3.6: "OngoingNextMeeting's
     *     self-loop is preserved as a property of that stage"). This is
     *     what keeps medical_package_default bit-identical.
     *   - Any other final stage (e.g. a template ending in `follow_up`)
     *     returns null → advance() refuses with a 422. Not a silent
     *     no-op: see advance() for why.
     *
     * TODO: CONFIRM (business rule) — the self-loop is applied only when
     * `ongoing_next_meeting` is the LAST stage of the template. A
     * template that puts it in the MIDDLE (e.g. ... -> ongoing_next_meeting
     * -> follow_up) advances forward normally instead, because a
     * loop-always reading would make that stage an inescapable trap and
     * would contradict the template's own ordering. PipelineTemplateResolver
     * does not currently forbid a mid-journey ongoing_next_meeting, so a
     * human should either confirm this behaviour or rule that the stage
     * may only ever be last (which would then be validated in TASK-134's
     * authoring rules).
     *
     * @throws ValidationException when the referral's journey cannot be
     *                             read at all (fail-closed — see stageSequenceFor())
     */
    public function nextStageFor(Referral $referral): ?PipelineStage
    {
        $sequence = $this->stageSequenceFor($referral);
        $index = $this->positionOf($referral, $sequence);

        $next = $sequence[$index + 1] ?? null;

        if ($next === null && $referral->current_stage === PipelineStage::OngoingNextMeeting) {
            return PipelineStage::OngoingNextMeeting;
        }

        return $next;
    }

    /**
     * TASK-136 — the referral's own journey, as READ-ONLY data for the
     * API layer, and the ONLY method here that never throws.
     *
     * WHY IT EXISTS
     * -------------
     * Both Kanban boards (frontend/src/views/PipelineView.vue,
     * frontend-admin/src/views/ReferralPipelineManagementView.vue) render
     * five hardcoded columns — CLAUDE.md §4.3's medical journey, from
     * before ADR-026 made the sequence config. A `direct_sale_default`
     * referral therefore appears on a five-column board, and dragging it
     * onto a stage its template does not contain 422s. ag-ui needs the
     * real sequence PER REFERRAL, and it must come from here rather than
     * being re-derived in Vue (§3 — business logic never in a component;
     * §7 — one source of truth for the NULL-snapshot fallback).
     *
     * WHY IT SWALLOWS THE FAIL-CLOSED ValidationException
     * ---------------------------------------------------
     * stageSequenceFor() refuses (422) for a referral whose template is
     * missing/emptied/mismatched — correct for advance(), which is about
     * to write money. Serialising a LIST must not 422 because one row of
     * fifty is misconfigured: that would blank the whole board. So a
     * broken journey renders as an empty sequence with no next stage,
     * which is still fail-closed at the UI (no columns to drop into) and
     * still fail-closed at the API (advance() itself keeps refusing).
     *
     * @return array{stages: list<PipelineStage>, next: ?PipelineStage}
     */
    public function journeyFor(Referral $referral): array
    {
        try {
            $stages = $this->stageSequenceFor($referral);
        } catch (ValidationException) {
            return ['stages' => [], 'next' => null];
        }

        try {
            $next = $this->nextStageFor($referral);
        } catch (ValidationException) {
            // The sequence read fine but the referral is parked at a
            // stage that is not on it (positionOf() refuses). Show the
            // journey, offer no move.
            $next = null;
        }

        return ['stages' => $stages, 'next' => $next];
    }

    /**
     * Is this referral AT or PAST $target on its own journey?
     *
     * ADR-026 §3.7 — "past it" is computed from the template's own
     * ordering, never from enum case order (the enum is a vocabulary,
     * not a sequence: a template may place `delivery` before or after
     * `follow_up`, and neither order is "later" in enum terms).
     *
     * Returns false when either stage is absent from the journey, so a
     * caller gating a money action on this fails CLOSED.
     *
     * @throws ValidationException when the referral's journey cannot be read
     */
    public function hasReachedStage(Referral $referral, PipelineStage $target): bool
    {
        $offset = $this->offsetFrom($referral, $target);

        return $offset !== null && $offset >= 0;
    }

    /**
     * Is this referral STRICTLY BEFORE $target on its own journey?
     *
     * TASK-170 — deliberately NOT expressible as `! hasReachedStage()`.
     * That negation is fail-OPEN: hasReachedStage() answers false both
     * for "not there yet" and for "I cannot locate this referral (or
     * $target) on its own journey at all", and the caller that needs
     * this method (ReferralService::setCoAgent(), the BR-4 co-agent edit
     * cutoff) must refuse in the second case, not permit. Both methods
     * read the SAME offsetFrom() below, so there is still exactly one
     * implementation of "where is this referral relative to $target".
     *
     * Returns false when either stage is absent from the journey — the
     * same fail-CLOSED direction as hasReachedStage(), for the opposite
     * question.
     *
     * @throws ValidationException when the referral's journey cannot be read
     */
    public function isBeforeStage(Referral $referral, PipelineStage $target): bool
    {
        $offset = $this->offsetFrom($referral, $target);

        return $offset !== null && $offset < 0;
    }

    /**
     * How many steps the referral's current stage sits AFTER $target on
     * its own journey — negative when it is still before $target, 0 when
     * it is exactly there.
     *
     * NULL means "cannot be located": the current stage, or $target, is
     * not on this journey at all. Both public callers above treat that as
     * "no", each in the direction that refuses the action, so a rewritten
     * template or a hand-edited row can never widen what is allowed.
     *
     * @throws ValidationException when the referral's journey cannot be read
     */
    private function offsetFrom(Referral $referral, PipelineStage $target): ?int
    {
        $sequence = $this->stageSequenceFor($referral);

        $currentIndex = array_search($referral->current_stage, $sequence, true);
        $targetIndex = array_search($target, $sequence, true);

        if ($currentIndex === false || $targetIndex === false) {
            return null;
        }

        return $currentIndex - $targetIndex;
    }

    /**
     * The stage that immediately precedes $target on this referral's own
     * journey — i.e. the one stage from which advancing reaches it.
     * Null when $target is absent, or is the entry stage.
     *
     * Used by OrderService to say WHICH step is still missing, instead
     * of naming a medical stage that may not exist on this journey.
     *
     * @throws ValidationException when the referral's journey cannot be read
     */
    public function stageBefore(Referral $referral, PipelineStage $target): ?PipelineStage
    {
        $sequence = $this->stageSequenceFor($referral);
        $index = array_search($target, $sequence, true);

        if ($index === false || $index === 0) {
            return null;
        }

        return $sequence[$index - 1];
    }

    /**
     * The ordered journey THIS referral is walking.
     *
     * - Template snapshot set (ADR-026 §3.4) → that template's stages.
     * - Snapshot NULL (every pre-ADR-026 referral) → CLAUDE.md §4.3's
     *   original five stages via PipelineStage::defaultSequence(), so
     *   legacy rows keep advancing exactly as they always have.
     *
     * BR-6: the template is re-read filtered on the REFERRAL's own
     * company_id rather than relying on TenantScope — identical
     * reasoning to PipelineTemplateResolver::findInCompany(). TenantScope
     * is exempt for Super Admin and a no-op on unauthenticated public
     * routes (the pay page, TASK-136's checkout, TASK-139's webhook),
     * i.e. it is absent in exactly the contexts that matter most.
     *
     * FAIL-CLOSED (§6): a snapshot that does not resolve inside the
     * referral's own company, or a template whose stages have been
     * emptied, throws instead of quietly falling back to the default
     * journey. Falling back would silently move a customer onto a
     * different journey — and, for a template edited down to nothing,
     * could skip them straight to a commission-triggering stage.
     *
     * @return list<PipelineStage>
     *
     * @throws ValidationException
     */
    private function stageSequenceFor(Referral $referral): array
    {
        $cacheKey = (string) ($referral->pipeline_template_id ?? 'default');

        if (isset($this->sequenceCache[$cacheKey])) {
            return $this->sequenceCache[$cacheKey];
        }

        if (! $referral->pipeline_template_id) {
            return $this->sequenceCache[$cacheKey] = PipelineStage::defaultSequence();
        }

        $template = PipelineTemplate::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $referral->company_id)
            ->find($referral->pipeline_template_id);

        if (! $template) {
            Log::warning("PipelineService: referral {$referral->id} is snapshotted with pipeline_template {$referral->pipeline_template_id}, which does not exist within company {$referral->company_id} (BR-6). Refusing to advance rather than falling back to another journey.");

            throw ValidationException::withMessages([
                'referral' => 'ไม่พบแม่แบบเส้นทางการขาย (pipeline template) ของรายการนี้ จึงไม่สามารถเปลี่ยนสถานะได้ กรุณาติดต่อผู้ดูแลระบบ',
            ]);
        }

        $sequence = $template->stageSequence();

        if ($sequence === []) {
            Log::warning("PipelineService: pipeline_template {$template->id} (company {$template->company_id}) has no stages — referral {$referral->id} cannot advance. A template must contain at least complete_registered and complete_payment (CLAUDE.md §4.3 / ADR-026 §3.5).");

            throw ValidationException::withMessages([
                'referral' => 'แม่แบบเส้นทางการขายของรายการนี้ไม่มีขั้นตอนใดเลย จึงไม่สามารถเปลี่ยนสถานะได้ กรุณาติดต่อผู้ดูแลระบบ',
            ]);
        }

        return $this->sequenceCache[$cacheKey] = $sequence;
    }

    /**
     * Where the referral currently stands on $sequence. A stage that is
     * not on its own journey at all is unreachable through advance()
     * (forward-only from the entry stage) — it means the row was
     * hand-edited or its template was rewritten under it, so this
     * refuses rather than guessing a position (§6, ADR-026 §3.4).
     *
     * @param  list<PipelineStage>  $sequence
     *
     * @throws ValidationException
     */
    private function positionOf(Referral $referral, array $sequence): int
    {
        $index = array_search($referral->current_stage, $sequence, true);

        if ($index === false) {
            Log::warning("PipelineService: referral {$referral->id} is at stage '{$referral->current_stage->value}', which is not part of its own pipeline template ({$referral->pipeline_template_id}). Refusing to advance (fail-closed).");

            throw ValidationException::withMessages([
                'referral' => 'สถานะปัจจุบันของรายการนี้ไม่อยู่ในแม่แบบเส้นทางการขายของตัวเอง จึงไม่สามารถเปลี่ยนสถานะได้ กรุณาติดต่อผู้ดูแลระบบ ('.$referral->current_stage->label().')',
            ]);
        }

        return $index;
    }
}
