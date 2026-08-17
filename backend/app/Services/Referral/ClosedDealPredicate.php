<?php

namespace App\Services\Referral;

use App\Enums\PipelineStage;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * TASK-179 §3.1 (human decision D4, 2026-08-13) — the ONE answer to
 * "has this deal closed?", expressed as SQL so an aggregate can ask it
 * of ten thousand referrals in one query.
 *
 * THE DEFINITION (D4)
 * -------------------
 * A referral has closed iff it has REACHED Complete Payment — including
 * every stage that comes after it (ADR-026 §5 Q1's จัดส่ง / นัดใช้บริการ /
 * ติดตามผล, and `ongoing_next_meeting`). Advancing a paid deal must never
 * reduce the close rate; that was finding F-3.
 *
 *     closed  ⇔  referrals.current_stage = 'complete_payment'
 *                OR a pipeline_stage_logs row exists for it with
 *                   to_stage = 'complete_payment'
 *
 * WHY AN EVENT, NOT A POSITION
 * ----------------------------
 * Since ADR-026 the stage SEQUENCE is per-product config, so "at or past
 * Complete Payment" is only answerable against that referral's own
 * template. PipelineService::hasReachedStage() does exactly that and is
 * the correct per-referral answer — but it resolves a template per call,
 * so calling it inside an aggregate is an N-query loop over the whole
 * company. Reaching payment is also an EVENT, and the event is already
 * recorded: this predicate reads that instead, which needs no template
 * resolution and no stage ordering at all.
 *
 * WHY THE `current_stage` HALF IS STILL THERE
 * -------------------------------------------
 * The log is written by PipelineService::advance() inside the same
 * transaction as the stage change, and advance() is the only code path in
 * the application that can set current_stage to complete_payment (verified
 * 2026-08-13: ReferralService / AffiliateLeadCaptureService /
 * ProductShareCheckoutService all create at complete_registered and write
 * their own entry log; TASK-134a's backfill migration touches only
 * pipeline_template_id; no seeder or console command writes the column
 * directly). So in application-written data the two halves agree.
 *
 * They can disagree for rows NOT written by the application — a
 * hand-edited row, a fixture built straight from ReferralFactory, a future
 * import. The OR keeps those counted rather than silently dropping them:
 * for a metric whose entire purpose is to stop under-reporting, the union
 * is the fail-safe direction. It is deliberately NOT "log only".
 *
 * Every caller must scope its own query by company_id first (BR-6) — this
 * class only narrows to "closed", it is not a tenant filter.
 */
final class ClosedDealPredicate
{
    /**
     * Narrow a query over `referrals` to the closed ones.
     *
     * @param  QueryBuilder|EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $referralsTable  the alias the referrals table is joined under, when it is not `referrals`
     */
    public static function apply(QueryBuilder|EloquentBuilder $query, string $referralsTable = 'referrals'): void
    {
        // Wrapped in its own where(Closure) group so the OR can never leak
        // out and widen a caller's company_id / agent_id filters — an
        // un-grouped orWhere here would be a BR-6 hole, not a cosmetic bug.
        $query->where(function ($grouped) use ($referralsTable) {
            $grouped
                ->where($referralsTable.'.current_stage', PipelineStage::CompletePayment->value)
                ->orWhereExists(function (QueryBuilder $sub) use ($referralsTable) {
                    $sub->select(DB::raw('1'))
                        ->from('pipeline_stage_logs')
                        ->whereColumn('pipeline_stage_logs.referral_id', $referralsTable.'.id')
                        ->where('pipeline_stage_logs.to_stage', PipelineStage::CompletePayment->value);
                });
        });
    }

    /**
     * Narrow a query over `referrals` to the OPEN ones — the exact
     * complement of apply(), for the screens that ask "what is still left
     * to work?" rather than "what has been sold?".
     *
     * TASK-180 §2 (A1) — this exists so that "open" cannot drift from
     * "closed". MeService used to answer it with its own
     * `whereNotIn(current_stage, [complete_payment, ongoing_next_meeting])`,
     * which since ADR-026 reported a deal parked at จัดส่ง / นัดใช้บริการ /
     * ติดตามผล back to the agent as work still to do. Written as a literal
     * SQL negation of apply() rather than as a second stage list: whatever
     * apply() decides, this decides the opposite, in the same commit, with
     * no second definition to update.
     *
     * `referrals.current_stage` is NOT NULL (see its migration), so the
     * three-valued-logic trap of NOT(NULL = 'x') cannot arise here.
     *
     * Callers still scope by company_id / agent_id themselves (BR-6) — the
     * NOT(...) is nested in its own group, so it can neither leak out of
     * nor widen those filters.
     *
     * @param  QueryBuilder|EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $referralsTable  the alias the referrals table is joined under, when it is not `referrals`
     */
    public static function applyOpen(QueryBuilder|EloquentBuilder $query, string $referralsTable = 'referrals'): void
    {
        $query->whereNot(function ($grouped) use ($referralsTable) {
            self::apply($grouped, $referralsTable);
        });
    }
}
