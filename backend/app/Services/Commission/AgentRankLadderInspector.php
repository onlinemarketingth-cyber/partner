<?php

namespace App\Services\Commission;

use App\Enums\CommissionRateType;
use App\Models\AgentRank;

/**
 * THE RANK LADDER'S OWN INVARIANTS, IN ONE PLACE.
 *
 * ═══ WHY THIS EXISTS ═══
 *
 * Owner decisions 2026-09-24, on the five silent failures of the
 * Stairstep plan (choices 1ก / 2ก / 3ก). Four of the five are states a
 * ladder can be SAVED IN today while nothing anywhere says so:
 *
 *   · rate types mixed (% beside fixed satang) — payDifferentialOverride()
 *     refuses to subtract one from the other and skips the pair, so the
 *     manager silently gets no row at all;
 *   · rate_value not increasing with volume_threshold — the differential
 *     comes out <= 0, and "never a $0 ledger row" turns that into silence
 *     too. Selling MORE earns the company's leaders LESS;
 *   · two ranks at the same volume_threshold — recalculateRanks() takes
 *     `orderByDesc('volume_threshold')->first(...)`, so which of the two an
 *     agent lands on is whatever order the database felt like returning;
 *   · no rank at volume_threshold = 0 — an agent who clears nothing keeps
 *     current_rank_id = null, which after the 2ก change means their manager
 *     is paid nothing on their sales.
 *
 * All four are questions about the LADDER ALONE — no sales, no agents, no
 * dates. That is why they live here as a pure function over rungs rather
 * than inside either caller: the write path (StoreAgentRankRequest /
 * UpdateAgentRankRequest / AgentRankService) and the read path
 * (CommissionReadinessService's banner) have to agree exactly about what
 * "this ladder is broken" means, and the fastest way for two copies of a
 * money rule to drift is to let there be two copies.
 *
 * ═══ WHY IT TAKES ARRAYS AND NOT MODELS ═══
 *
 * The write path's real question is counterfactual — "what would this
 * ladder look like if I saved this?" — and answering it with Eloquent
 * models means either persisting a row to find out, or building an unsaved
 * model whose casts and defaults may not match a hydrated one. Plain rungs
 * make the hypothetical ladder trivial to construct and the whole class
 * unit-testable without touching a database.
 *
 * ═══ THE LINE BETWEEN BLOCKING AND ADVISORY ═══
 *
 * BLOCKING is the three findings that make money WRONG or
 * UNPREDICTABLE. The write path does not refuse them outright — it refuses
 * to let their count GO UP (see regressions()). Owner choice 3ก, and the
 * reason is the deadlock the strict version creates: a company that already
 * holds a mixed-type ladder could not edit any rank, including the edit
 * that would fix it.
 *
 * The other two are INCOMPLETENESS, not corruption, and blocking on them
 * would trap an admin halfway through building a ladder — you cannot add
 * the threshold-0 rank first if adding any rank at all is refused until one
 * exists. They are reported by the banner only.
 */
class AgentRankLadderInspector
{
    /** Two ranks priced in different units — the pair is skipped, silently. */
    public const MIXED_RATE_TYPES = 'mixed_rate_types';

    /** A higher threshold priced at or below a lower one — differential <= 0. */
    public const RATE_NOT_INCREASING = 'rate_not_increasing';

    /** Two ranks at the same threshold — which one an agent gets is arbitrary. */
    public const DUPLICATE_THRESHOLDS = 'duplicate_thresholds';

    /** Nobody to fall back on when an agent clears no threshold. */
    public const NO_ENTRY_RANK = 'no_entry_rank';

    /** Ranks that only earn while their downline has not broken away yet. */
    public const RANKS_ABOVE_BREAKAWAY = 'ranks_above_breakaway';

    /** @var list<string> */
    public const BLOCKING = [
        self::MIXED_RATE_TYPES,
        self::RATE_NOT_INCREASING,
        self::DUPLICATE_THRESHOLDS,
    ];

    /**
     * @param  list<array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}>  $rungs
     * @return array<string, int> finding code => count, zero counts omitted
     */
    public function inspect(array $rungs): array
    {
        /*
         * An EMPTY ladder is not a broken ladder.
         *
         * A company on Stairstep with no ranks at all is already reported by
         * CommissionReadinessService as a missing plan structure, and saying
         * "no entry rank" beside that would be the same gap counted twice —
         * which is how an amber banner turns into wallpaper.
         */
        if ($rungs === []) {
            return [];
        }

        $findings = [];

        $rateTypes = array_unique(array_map(
            static fn (array $rung) => $rung['rate_type']->value,
            $rungs,
        ));

        if (count($rateTypes) > 1) {
            $findings[self::MIXED_RATE_TYPES] = count($rateTypes);
        }

        /*
         * The rate comparison is only asked when the ladder is priced in one
         * unit. "Is 20% more than ฿500" has no answer that does not depend
         * on the sale, and reporting a made-up one beside the mixed-types
         * finding would tell an admin to fix a second thing that will
         * resolve itself the moment they fix the first.
         */
        if (count($rateTypes) === 1) {
            $notIncreasing = $this->countRatesNotIncreasing($rungs);

            if ($notIncreasing > 0) {
                $findings[self::RATE_NOT_INCREASING] = $notIncreasing;
            }
        }

        $duplicates = $this->countDuplicateThresholds($rungs);

        if ($duplicates > 0) {
            $findings[self::DUPLICATE_THRESHOLDS] = $duplicates;
        }

        $hasEntryRank = array_filter($rungs, static fn (array $rung) => $rung['volume_threshold'] === 0) !== [];

        if (! $hasEntryRank) {
            $findings[self::NO_ENTRY_RANK] = 1;
        }

        $aboveBreakaway = $this->countRanksAboveBreakaway($rungs);

        if ($aboveBreakaway > 0) {
            $findings[self::RANKS_ABOVE_BREAKAWAY] = $aboveBreakaway;
        }

        return $findings;
    }

    /**
     * The same findings, narrowed to the three the write path guards.
     *
     * @param  list<array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}>  $rungs
     * @return array<string, int>
     */
    public function blocking(array $rungs): array
    {
        return array_intersect_key($this->inspect($rungs), array_flip(self::BLOCKING));
    }

    /**
     * WHICH BLOCKING FINDINGS A PROPOSED SAVE WOULD MAKE WORSE.
     *
     * Monotone non-increasing, deliberately, and it is the whole of owner
     * choice 3ก: a count that stays the same or falls is allowed through,
     * a count that rises is refused. That rule cannot deadlock — from any
     * ladder, however broken, there is always a sequence of edits that
     * lowers the counts to zero, and none of them is blocked on the way.
     *
     * A strict "the result must be valid" check would refuse every one of
     * those edits, because each intermediate ladder is still invalid.
     *
     * @param  list<array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}>  $before
     * @param  list<array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}>  $after
     * @return array<string, int> code => the count it would rise to
     */
    public function regressions(array $before, array $after): array
    {
        $was = $this->blocking($before);
        $would = $this->blocking($after);

        $worse = [];

        foreach ($would as $code => $count) {
            if ($count > ($was[$code] ?? 0)) {
                $worse[$code] = $count;
            }
        }

        return $worse;
    }

    /**
     * The same question asked about a company's live ladder.
     *
     * $replacing null means a create. Used by both write paths — the Form
     * Requests, so the admin gets the error on the field they are editing,
     * and AgentRankService, so a seeder or command cannot walk around them
     * (the same belt-and-braces CLAUDE.md Section 4.3 requires of pipeline
     * templates).
     *
     * withoutGlobalScopes() plus an explicit company_id: a Super Admin
     * editing another company's ladder has no ambient tenant scope, so the
     * boundary has to be said out loud (BR-6).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, int>
     */
    public function regressionsForCompany(int $companyId, ?AgentRank $replacing, array $attributes): array
    {
        $current = AgentRank::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        return $this->regressions(
            self::rungsFrom($current),
            self::rungsWith($current, $replacing, $attributes),
        );
    }

    /**
     * Save-time copy: what the ladder would become, and that it is refused
     * for being worse rather than for being imperfect.
     *
     * @param  array<string, int>  $regressions
     * @return list<string>
     */
    public function regressionMessages(array $regressions): array
    {
        $messages = [];

        foreach ($regressions as $code => $count) {
            $messages[] = 'บันทึกไม่ได้ เพราะจะทำให้บันไดอันดับแย่ลงกว่าเดิม — '.$this->label($code, $count);
        }

        return $messages;
    }

    /**
     * Rungs as the database holds them.
     *
     * @param  iterable<AgentRank>  $ranks
     * @return list<array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}>
     */
    public static function rungsFrom(iterable $ranks): array
    {
        $rungs = [];

        foreach ($ranks as $rank) {
            $rungs[] = [
                'volume_threshold' => (int) $rank->volume_threshold,
                'rate_type' => $rank->rate_type,
                'rate_value' => (int) $rank->rate_value,
                'is_breakaway_rank' => (bool) $rank->is_breakaway_rank,
            ];
        }

        return $rungs;
    }

    /**
     * The ladder AS IT WOULD BE after a proposed create or update.
     *
     * $replacing null means a create (the rung is appended); otherwise that
     * rank's rung is swapped for the proposed one. Update requests validate
     * with `sometimes`, so $attributes is merged ONTO the existing row
     * rather than replacing it — a request that only moves sort_order must
     * not read as one that blanked the rate.
     *
     * @param  iterable<AgentRank>  $current
     * @param  array<string, mixed>  $attributes
     * @return list<array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}>
     */
    public static function rungsWith(iterable $current, ?AgentRank $replacing, array $attributes): array
    {
        $rungs = [];
        $replaced = false;

        foreach ($current as $rank) {
            $isTarget = $replacing !== null && (int) $rank->id === (int) $replacing->id;

            if (! $isTarget) {
                $rungs[] = self::rungsFrom([$rank])[0];

                continue;
            }

            $rungs[] = self::rungFromAttributes($attributes, $rank);
            $replaced = true;
        }

        if ($replacing === null) {
            $rungs[] = self::rungFromAttributes($attributes, null);
        } elseif (! $replaced) {
            /*
             * The target is not in $current — a Super Admin editing a rank
             * while $current was read for a different company, or a race with
             * a delete. Appending it would invent a ladder that never exists;
             * leaving it out would silently drop the row being edited from
             * its own validation. Append is the safer of the two: it can only
             * make the "after" ladder look WORSE, never better, so the guard
             * errs toward refusing rather than waving through.
             */
            $rungs[] = self::rungFromAttributes($attributes, $replacing);
        }

        return $rungs;
    }

    /** Thai copy, shared by the save error and the readiness banner. */
    public function label(string $code, int $count): string
    {
        return match ($code) {
            self::MIXED_RATE_TYPES => "บันไดอันดับใช้ชนิดอัตราปนกัน {$count} ชนิด — ขั้นที่ชนิดต่างกันจะถูกข้าม หัวหน้าไม่ได้ส่วนต่างเลย",
            self::RATE_NOT_INCREASING => "มีขั้นที่ยอดสูงกว่าแต่อัตราไม่สูงกว่า {$count} จุด — ส่วนต่างเป็นศูนย์หรือติดลบ จึงไม่มีการจ่าย",
            self::DUPLICATE_THRESHOLDS => "มีขั้นที่ยอดขั้นต่ำซ้ำกัน {$count} ขั้น — ระบบจะจัดให้ขั้นไหนก็ได้ ทำนายไม่ได้",
            self::NO_ENTRY_RANK => 'ยังไม่มีขั้นที่ยอดขั้นต่ำเป็น ฿0 — คนที่ยังทำยอดไม่ถึงเกณฑ์ใดเลยจะไม่มีขั้น และหัวหน้าจะไม่ได้ส่วนต่างจากดีลของเขา',
            self::RANKS_ABOVE_BREAKAWAY => "มีขั้นอยู่เหนือขั้นตัดสาย {$count} ขั้น — ขั้นเหล่านี้จะได้ส่วนต่างเฉพาะตอนที่ลูกทีมยังไม่ถึงขั้นตัดสายเท่านั้น",
            default => $code,
        };
    }

    /**
     * Adjacent pairs, ordered by threshold, where the higher rung is priced
     * at or below the lower one.
     *
     * `<=` rather than `<`: two ranks on the same rate produce a
     * differential of exactly 0, and "never a $0 ledger row" means the
     * higher rank earns nothing at all from the lower. That is the same
     * silence as a negative differential, so it is the same finding.
     *
     * Ranks sharing a threshold are compared within their own group first
     * (sorted by rate) and only the group's edges meet the next threshold —
     * a duplicate threshold is its own finding and must not also manufacture
     * a rate finding.
     *
     * @param  list<array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}>  $rungs
     */
    private function countRatesNotIncreasing(array $rungs): int
    {
        $sorted = $rungs;

        usort($sorted, static fn (array $a, array $b) => [$a['volume_threshold'], $a['rate_value']] <=> [$b['volume_threshold'], $b['rate_value']]);

        $count = 0;

        for ($i = 1, $n = count($sorted); $i < $n; $i++) {
            if ($sorted[$i]['volume_threshold'] === $sorted[$i - 1]['volume_threshold']) {
                continue;
            }

            if ($sorted[$i]['rate_value'] <= $sorted[$i - 1]['rate_value']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * How many RANKS sit on a contested threshold — not how many thresholds
     * are contested. An admin has to open every one of those rows to decide
     * which survives, the same count CommissionReadinessService reports for
     * overlapping rate rules and for the same reason.
     *
     * @param  list<array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}>  $rungs
     */
    private function countDuplicateThresholds(array $rungs): int
    {
        $byThreshold = [];

        foreach ($rungs as $rung) {
            $byThreshold[$rung['volume_threshold']] = ($byThreshold[$rung['volume_threshold']] ?? 0) + 1;
        }

        $count = 0;

        foreach ($byThreshold as $occurrences) {
            if ($occurrences > 1) {
                $count += $occurrences;
            }
        }

        return $count;
    }

    /**
     * Measured against THRESHOLD, not sort_order.
     *
     * sort_order is display order and nothing else — recalculateRanks()
     * assigns ranks purely by `volume_threshold`, so "above the breakaway
     * rank" has to mean "reachable only by clearing more volume than the
     * breakaway rank needs", which is the group that suffers problem 5.5.
     *
     * @param  list<array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}>  $rungs
     */
    private function countRanksAboveBreakaway(array $rungs): int
    {
        $breakawayThresholds = array_map(
            static fn (array $rung) => $rung['volume_threshold'],
            array_filter($rungs, static fn (array $rung) => $rung['is_breakaway_rank']),
        );

        if ($breakawayThresholds === []) {
            return 0;
        }

        $lowest = min($breakawayThresholds);

        return count(array_filter($rungs, static fn (array $rung) => $rung['volume_threshold'] > $lowest));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}
     */
    private static function rungFromAttributes(array $attributes, ?AgentRank $existing): array
    {
        $rateType = $attributes['rate_type'] ?? $existing?->rate_type ?? CommissionRateType::Percentage;

        return [
            'volume_threshold' => (int) ($attributes['volume_threshold'] ?? $existing?->volume_threshold ?? 0),
            'rate_type' => $rateType instanceof CommissionRateType ? $rateType : CommissionRateType::from((string) $rateType),
            'rate_value' => (int) ($attributes['rate_value'] ?? $existing?->rate_value ?? 0),
            'is_breakaway_rank' => (bool) ($attributes['is_breakaway_rank'] ?? $existing?->is_breakaway_rank ?? false),
        ];
    }
}
