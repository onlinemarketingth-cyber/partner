<?php

namespace App\Services\Commission;

use App\Enums\AgentRankRecalculationFrequency;
use App\Enums\AgentRankVolumeScope;
use App\Enums\CommissionEarnedVia;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Enums\UserRole;
use App\Models\AgentRank;
use App\Models\AgentRankSetting;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Referral;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADR-011 Section 3c (TASK-031) — Stairstep/Breakaway MLM plan type.
 * Two deliberately separate responsibilities, mirroring the Binary/
 * Matrix Services' own split:
 *
 *   - recalculateRanks() runs on a SCHEDULE (RecalculateAgentRanks) and
 *     is the only thing that ever writes users.current_rank_id.
 *   - payDifferentialOverride() runs SYNCHRONOUSLY inside
 *     CommissionService::recordForReferral() (Complete Payment) and
 *     only ever READS current_rank_id — it never recalculates rank
 *     mid-sale (ranks are a periodic snapshot, not computed live, same
 *     "config gap is a human problem, not something to guess at" stance
 *     as every other Service in this family).
 */
class StairstepCommissionService
{
    // Same circuit-breaker rationale as every other manager_id walk in
    // this Service family (Section 7 "no magic numbers" — not a real
    // business-defined depth cap).
    private const MAX_CHAIN_DEPTH = 100;

    /**
     * Sweeps every company with agent_rank_settings configured and
     * recalculates current_rank_id for every Agent whose company's
     * recalculation cadence is now due.
     *
     * @return int number of agents recalculated this run
     */
    public function recalculateRanks(): int
    {
        $processed = 0;

        Company::whereHas('agentRankSetting')
            ->with('agentRankSetting')
            ->chunkById(50, function ($companies) use (&$processed) {
                foreach ($companies as $company) {
                    $processed += $this->recalculateCompanyRanks($company);
                }
            });

        return $processed;
    }

    private function recalculateCompanyRanks(Company $company): int
    {
        /** @var AgentRankSetting $settings */
        $settings = $company->agentRankSetting;

        $intervalDays = match ($settings->recalculation_frequency) {
            AgentRankRecalculationFrequency::Daily => 1,
            AgentRankRecalculationFrequency::Weekly => 7,
            AgentRankRecalculationFrequency::Monthly => 30,
        };

        $due = $settings->last_recalculated_at === null
            || $settings->last_recalculated_at->lte(now()->subDays($intervalDays));

        if (! $due) {
            return 0;
        }

        // Highest volume_threshold first — the first rank an agent's
        // trailing volume clears (walking from the top down) is their
        // new rank; an agent clearing no threshold at all keeps
        // current_rank_id = null (un-ranked), same "no row = no
        // guessed default" stance as every BR-7 lookup elsewhere.
        $ranks = AgentRank::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderByDesc('volume_threshold')
            ->get();

        $processed = 0;

        /*
         * Resolved ONCE for the whole company, before the chunk loop, not
         * per agent inside it.
         *
         * Under group scope a per-agent answer means walking that agent's
         * whole subtree, so N agents would mean N tree walks — the same
         * shape of problem DownlineService's memo exists to avoid, except
         * here it is a scheduled job sweeping every tenant. One pass over
         * the company's sales plus one pass up the manager chain answers it
         * for everybody.
         *
         * @var array<int, int> agent id => trailing volume in satang
         */
        $volumes = $this->trailingVolumesForCompany($company, $settings);

        // withoutGlobalScopes() — background job, same rationale as
        // BinaryCommissionService::processCompanyCycles(); explicit
        // company_id/role filtering below does the real scoping.
        User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('role', UserRole::Agent)
            ->chunkById(100, function ($agents) use ($volumes, $ranks, &$processed) {
                foreach ($agents as $agent) {
                    // No entry = sold nothing in the window (and, under
                    // group scope, nobody below them did either). Zero, not
                    // null — an agent with no sales is measured against the
                    // ladder like everybody else, and clears whatever rank
                    // has a threshold of 0 if the company defined one.
                    $volumeSatang = $volumes[$agent->id] ?? 0;

                    $newRank = $ranks->first(fn (AgentRank $rank) => $volumeSatang >= $rank->volume_threshold);

                    if ($agent->current_rank_id !== $newRank?->id) {
                        // forceFill(), not update() — current_rank_id is
                        // deliberately NOT in User::$fillable (see
                        // User::currentRank()'s own docblock: system-owned,
                        // never user-writable). This recalculation job is
                        // the one legitimate writer and must bypass mass-
                        // assignment protection explicitly rather than
                        // silently no-op through update().
                        $agent->forceFill(['current_rank_id' => $newRank?->id])->save();
                    }

                    $processed++;
                }
            });

        $settings->update(['last_recalculated_at' => now()]);

        return $processed;
    }

    /**
     * Trailing volume for every agent in the company, in one pass.
     *
     * @return array<int, int> agent id => volume in satang
     */
    private function trailingVolumesForCompany(Company $company, AgentRankSetting $settings): array
    {
        $personal = $this->personalVolumesSatang((int) $company->id, $settings->trailing_window_days);

        if ($settings->volumeScope() !== AgentRankVolumeScope::Group) {
            return $personal;
        }

        return $this->rolledUpThroughTheManagerChain((int) $company->id, $personal);
    }

    /**
     * "Trailing sales volume" = the value of every referral an agent
     * personally sold (referrals.agent_id, not co_agent_id — same
     * "co-agent's own hierarchy is out of scope" precedent as
     * CommissionService::recordDirectSale()'s own TODO) that reached
     * Complete Payment within the trailing window.
     *
     * Reads pipeline_stage_logs.changed_at (the actual BR-4 trigger
     * moment) rather than referrals.current_stage, because a referral's
     * stage keeps advancing past Complete Payment (Section 4.3's own state
     * machine) — current_stage alone would silently drop any sale whose
     * pipeline has since moved on to a later meeting.
     *
     * ═══ WHAT "THE VALUE OF" MEANS, AND WHY IT CHANGED ═══
     *
     * This used to sum `products.price_satang`: the product's list price
     * TODAY. Three ways that is the wrong number, and none of them are
     * hypothetical now:
     *
     *   · A sale closed under a price promotion counted at full list price,
     *     so an agent qualified on money the company never took.
     *   · A company on PV basis (commission_basis = pv) qualified ranks on
     *     baht while it paid commission on points — the two ladders measured
     *     different things, which is exactly what PV exists to prevent.
     *   · Editing a product's price silently re-ranked every historical
     *     sale of it. That is the live-recomputation BR-4 forbids, one table
     *     over: ranks drive the differential that writes ledger rows, so a
     *     price edit could change what a manager is paid on tomorrow's sale
     *     because of what a product cost last year.
     *
     * So the amount comes from the sale's own snapshot, newest-and-most-
     * specific first: the Direct ledger row's commission_base_satang_at_time
     * (the exact figure the rate was applied to — PV when the company is on
     * PV, the promoted price when a promotion applied), then that row's
     * sale_price_satang_at_time (rows written before TASK-214's basis
     * snapshot existed), and only then products.price_satang — which now
     * serves only sales with no Direct ledger row at all: a sale by an agent
     * who had no commission rule, and rows predating the snapshot columns.
     *
     * MAX(), not SUM(), over the Direct rows: a split sale (TASK-026)
     * writes two Direct rows for one referral and both carry the SAME
     * snapshot of the sale's value, because a split divides the commission,
     * not the sale. Summing them would double the referral's volume.
     *
     * The window subquery groups by referral_id so a referral that somehow
     * logged Complete Payment twice counts once. The old query joined the
     * log table directly and would have counted it twice.
     *
     * @return array<int, int> agent id => volume in satang
     */
    private function personalVolumesSatang(int $companyId, int $trailingWindowDays): array
    {
        $paidInWindow = DB::table('pipeline_stage_logs')
            ->select('referral_id')
            ->where('to_stage', PipelineStage::CompletePayment->value)
            ->where('changed_at', '>=', now()->subDays($trailingWindowDays))
            ->groupBy('referral_id');

        $saleValue = DB::table('commission_ledger')
            ->select('referral_id')
            ->selectRaw('MAX(commission_base_satang_at_time) as base_satang')
            ->selectRaw('MAX(sale_price_satang_at_time) as sale_satang')
            ->where('earned_via', CommissionEarnedVia::Direct->value)
            ->whereNotNull('referral_id')
            ->groupBy('referral_id');

        return DB::table('referrals')
            ->joinSub($paidInWindow, 'paid', 'paid.referral_id', '=', 'referrals.id')
            ->join('products', 'products.id', '=', 'referrals.product_id')
            ->leftJoinSub($saleValue, 'sale_value', 'sale_value.referral_id', '=', 'referrals.id')
            // BR-6 — explicit, not TenantScope: this runs from a scheduled
            // command with no authenticated user, where the scope is a no-op.
            ->where('referrals.company_id', $companyId)
            ->whereNotNull('referrals.agent_id')
            ->groupBy('referrals.agent_id')
            ->selectRaw('referrals.agent_id as agent_id')
            ->selectRaw('SUM(COALESCE(sale_value.base_satang, sale_value.sale_satang, products.price_satang)) as volume_satang')
            ->pluck('volume_satang', 'agent_id')
            ->mapWithKeys(fn ($volume, $agentId) => [(int) $agentId => (int) $volume])
            ->all();
    }

    /**
     * Group volume: each agent's own trailing volume plus every descendant's.
     *
     * Walks UP from each agent who sold something and adds their volume to
     * every ancestor, rather than walking DOWN from each agent — one pass
     * over the sellers instead of one subtree walk per agent, and the two
     * produce the same totals.
     *
     * Deliberate choices, each of which a reviewer should be able to
     * challenge:
     *
     *   · An agent's group volume INCLUDES their own personal volume. That
     *     is what GV means everywhere the term is published; a company that
     *     wants the two measured separately needs a second threshold column,
     *     which is a bigger change than this one and nobody has asked for it.
     *   · The manager map is built from live users, soft-deleted rows
     *     excluded — the same tree DownlineService shows a leader. A
     *     deactivated agent's sales therefore stop rolling up, which is the
     *     same answer the team screen already gives about that person.
     *   · A manager in another company stops the walk, because they are not
     *     in the map at all (BR-6).
     *   · The visited set is the cycle guard, on the same reasoning as
     *     DownlineService::walkSubtree(): assertValidManager() refuses to
     *     create a cycle on the write path, but a restored backup or a
     *     manual UPDATE can still hold one, and this runs unattended.
     *
     * ═══ A BROKEN-AWAY LEG STOPS COUNTING (owner decision, 2026-09-24) ═══
     *
     * This carried a `TODO: CONFIRM` until the owner answered it: a leg whose
     * holder has reached a breakaway rank no longer contributes to its former
     * upline's group volume. That is the other half of what "breakaway"
     * means — payDifferentialOverride() already stops PAYING past such a leg,
     * and leaving it counting would let a large broken-away organisation hold
     * its former upline at the top rung forever without that person doing
     * anything at all.
     *
     * The cut fires on the CHILD, exactly as it does in the payout walk: the
     * pair (breakaway child, their manager) is where the tie is severed, so
     * the child keeps every satang of their own subtree and the manager above
     * them receives none of it.
     *
     * ═══ THE RANKS THIS READS ARE LAST RUN'S ═══
     *
     * Rank assignment depends on group volume, which now depends on ranks.
     * That circle is broken the same way the rest of this Service breaks it:
     * `current_rank_id` is a periodic snapshot, so the exclusion uses the
     * ranks as they stand when the sweep BEGINS. One pass, deterministic, no
     * iteration to convergence — and a newly-broken-away leg stops counting
     * from the following run rather than the same one.
     *
     * ═══ THIS ALSO MOVES GENERATION ═══
     *
     * recalculateRanks() sweeps every company that has agent_rank_settings,
     * not every Stairstep company, and GenerationCommissionService draws its
     * generation boundaries at whoever holds a breakaway rank. So a company
     * on Generation with group scope feels this too: fewer people reach the
     * breakaway rung, and its generations begin in different places.
     *
     * @param  array<int, int>  $personal
     * @return array<int, int>
     */
    private function rolledUpThroughTheManagerChain(int $companyId, array $personal): array
    {
        // Eloquent, dropping only TenantScope — SoftDeletes stays on, same
        // reasoning as DownlineService::companyScoped().
        $managerOf = User::withoutGlobalScope(TenantScope::class)
            ->where('company_id', $companyId)
            ->pluck('manager_id', 'id')
            ->mapWithKeys(fn ($managerId, $id) => [(int) $id => $managerId === null ? null : (int) $managerId])
            ->all();

        $breakawayHolders = $this->breakawayHolders($companyId);

        $group = $personal;

        foreach ($personal as $sellerId => $volumeSatang) {
            $visited = [$sellerId => true];
            $childId = (int) $sellerId;
            $ancestorId = $managerOf[$sellerId] ?? null;
            $depth = 0;

            while ($ancestorId !== null && $depth < self::MAX_CHAIN_DEPTH && ! isset($visited[$ancestorId])) {
                // The tie is cut between a breakaway holder and the manager
                // ABOVE them — so the seller's own volume, and everything
                // below a breakaway leg, still reaches that leg's holder.
                if (isset($breakawayHolders[$childId])) {
                    break;
                }

                $visited[$ancestorId] = true;
                $group[$ancestorId] = ($group[$ancestorId] ?? 0) + $volumeSatang;

                $childId = $ancestorId;
                $ancestorId = $managerOf[$ancestorId] ?? null;
                $depth++;
            }
        }

        return $group;
    }

    /**
     * ag-lead judgment call on the exact walk algorithm (the human
     * approved the OVERALL rank-differential mechanism via the
     * ADR-011/TASK-031 design question, not every algorithmic detail —
     * same "mechanism confirmed, algorithm documented not asked" split
     * as MatrixCommissionService's own BFS placement docblock):
     *
     *   - Walks the selling agent's manager_id chain one hop at a time.
     *     At each hop, the ancestor (manager) earns the DIFFERENCE
     *     between their OWN rank's rate and the rate of the node
     *     directly below them in the walk (the "child") — never the
     *     cumulative difference back to the original seller. This is
     *     the standard differential/compression mechanic real Stairstep
     *     plans use so the same rate-points are never paid twice across
     *     the chain.
     *   - BEFORE paying a hop, if the CHILD has already reached a
     *     breakaway rank, the walk stops entirely (breaks) — a
     *     breakaway leg is "commission-independent of its former
     *     upline" (ADR-011), meaning nobody above the breakaway point
     *     earns anything further from that leg's sales, not even the
     *     immediate manager. This is the textbook definition of a
     *     breakaway plan (the leg becomes its own separate business).
     *   - A child with no current_rank yet (never recalculated / below
     *     the lowest rank) is treated as rate 0 — the manager earns
     *     their own full rank rate as the differential, same as if the
     *     child were the absolute floor of the ladder.
     *   - A manager whose own current_rank has a DIFFERENT rate_type
     *     than the child's rank makes the differential mathematically
     *     meaningless (can't subtract a percentage from a fixed-satang
     *     value) — that one hop is simply skipped (no ledger row), the
     *     walk continues past them, same "unconfigured = no row, not a
     *     guess" precedent as every other override mechanism here.
     *   - A manager with no current_rank at all is skipped the same way
     *     (nothing to compare, no row), walk continues.
     */
    public function payDifferentialOverride(Referral $referral, User $sellingAgent, int $productPriceSatang): void
    {
        $child = $sellingAgent;
        $manager = $sellingAgent->manager;
        $depth = 0;

        // Resolved lazily and at most once per sale — most chains contain no
        // un-ranked agent at all and must not pay for a query about one.
        $entryRank = null;
        $entryRankResolved = false;

        while ($manager !== null && $depth < self::MAX_CHAIN_DEPTH) {
            $childRank = $child->currentRank;

            /*
             * ═══ AN UN-RANKED DOWNLINE IS TREATED AS THE ENTRY RANK ═══
             *
             * Owner decision 2026-09-24 (choice 2ก).
             *
             * This used to read `$childRank->rate_value ?? 0`, which on a
             * null rank quietly became 0 — and a child rate of 0 makes the
             * differential the manager's FULL rank rate instead of a
             * difference. That is not a rare edge: current_rank_id starts
             * null on every new agent and only the scheduled recalculation
             * ever writes it, so with a monthly cadence a recruit's first
             * sale overpaid their manager for up to 30 days, into ledger
             * rows BR-4 forbids correcting. The plan's own guarantee — the
             * company never pays out more than the highest rank's rate in
             * the chain — was false exactly where it mattered most.
             *
             * The substitute is not a guess: an agent with no volume clears
             * the threshold-0 rank anyway, so this is the same answer the
             * next recalculation will write, reached sooner. It stands in
             * for the whole iteration, breakaway check included, because
             * "this child counts as the entry rank" has to mean one thing.
             */
            if ($childRank === null) {
                if (! $entryRankResolved) {
                    $entryRank = $this->entryRankFor((int) $referral->company_id);
                    $entryRankResolved = true;
                }

                $childRank = $entryRank;
            }

            /*
             * No threshold-0 rank to stand in with: the company's ladder
             * cannot say what this agent's rate is, and CLAUDE.md Section 8
             * guardrail 1 says not to invent one. No row for THIS hop — the
             * walk continues, because the manager above has a rank of their
             * own and the next pair is perfectly well defined.
             *
             * CommissionReadinessService reports the missing entry rank, so
             * this silence is not the only signal the admin gets.
             */
            if ($childRank === null) {
                $child = $manager;
                $manager = $manager->manager;
                $depth++;

                continue;
            }

            if ($childRank->is_breakaway_rank) {
                break;
            }

            $managerRank = $manager->currentRank;

            if ($managerRank) {
                // Both ranks are real rows from here on — the null child was
                // resolved or skipped above — so the mismatch test no longer
                // has to double as a null check, and the child's rate is read
                // straight rather than through a `?? 0` that used to turn "no
                // rank" into "0%".
                $childRateTypeMismatch = $childRank->rate_type !== $managerRank->rate_type;

                if (! $childRateTypeMismatch) {
                    $differential = $managerRank->rate_value - $childRank->rate_value;

                    if ($differential > 0) {
                        $amountSatang = CommissionRateCalculator::compute($managerRank->rate_type, $differential, $productPriceSatang);

                        // Never a $0 ledger row — same BR-4 precedent as
                        // every other override mechanism in this family.
                        if ($amountSatang > 0) {
                            CommissionLedger::create([
                                'company_id' => $referral->company_id,
                                'agent_id' => $manager->id,
                                'referral_id' => $referral->id,
                                'cert_tier_id_at_time' => null,
                                'product_id' => $referral->product_id,
                                'rate_type_applied' => $managerRank->rate_type,
                                'rate_applied' => $differential,
                                'amount_satang' => $amountSatang,
                                'payment_status' => PaymentStatus::Pending,
                                'paid_at' => null,
                                'earned_via' => CommissionEarnedVia::StairstepOverride,
                                'override_source_agent_id' => $sellingAgent->id,
                            ]);
                        }
                    }
                }
            }

            $child = $manager;
            $manager = $manager->manager;
            $depth++;
        }
    }

    /**
     * Everyone in the company who currently holds a rank flagged as a
     * breakaway rung, as an id-keyed set.
     *
     * A join rather than `whereHas`, for the same reason every query in this
     * Service names its company out loud: this runs unattended with no
     * authenticated user, where TenantScope narrows nothing, and a rank set
     * that silently spanned tenants would move money in every one of them.
     *
     * ═══ NO SOFT-DELETE FILTER, BECAUSE ONE WOULD BE DEAD ═══
     *
     * A first draft had `whereNull('users.deleted_at')` here and a comment
     * justifying it. Mutating the filter away left every test green, and
     * chasing that led to the actual rule: `$managerOf` above is built with
     * SoftDeletes ON, so a deactivated agent has no entry in it. For an
     * ANCESTOR that means they can never be walked to; for a SELLER — whose
     * sales personalVolumesSatang() still counts, since it reads
     * `referrals.agent_id` and never asks about the user — it means
     * `$managerOf[$sellerId] ?? null` is null and the walk never starts.
     *
     * Either way a deactivated agent's volume stops with them before this set
     * is ever consulted, so filtering here decides nothing. It is left out
     * rather than kept as a condition no test can reach.
     *
     * @return array<int, true>
     */
    private function breakawayHolders(int $companyId): array
    {
        return DB::table('users')
            ->join('agent_ranks', 'agent_ranks.id', '=', 'users.current_rank_id')
            ->where('users.company_id', $companyId)
            ->where('agent_ranks.is_breakaway_rank', true)
            ->pluck('users.id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * The rank an agent holds when they have cleared nothing.
     *
     * `volume_threshold = 0` exactly, not "the lowest threshold there is".
     * A ladder whose cheapest rung needs ฿50,000 has no opinion about
     * somebody at ฿0, and borrowing that rung's rate would be inventing a
     * business value (BR-7) rather than reading one.
     *
     * Ties broken by sort_order then id so the substitute is the same row on
     * every sale — a ladder with two threshold-0 rungs is a finding of its
     * own (AgentRankLadderInspector::DUPLICATE_THRESHOLDS), and until it is
     * fixed the money must at least be repeatable.
     *
     * withoutGlobalScopes() for the same reason the rest of this Service
     * uses it: recordForReferral() also runs from a gateway confirmation
     * with no authenticated user, where TenantScope filters nothing. The
     * explicit company_id is the real BR-6 boundary here.
     */
    private function entryRankFor(int $companyId): ?AgentRank
    {
        return AgentRank::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('volume_threshold', 0)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }
}
