<?php

namespace Tests\Unit\Commission;

use App\Enums\CommissionRateType;
use App\Services\Commission\AgentRankLadderInspector;
use PHPUnit\Framework\TestCase;

/**
 * The rank ladder's invariants, asked without a database.
 *
 * ═══ WHY THESE ARE UNIT TESTS ═══
 *
 * The write path's real question is counterfactual — "what would this
 * ladder become if I saved this?" — and the answer must not depend on a
 * company, an agent, a sale or a date. Proving that here means the feature
 * tests over the HTTP endpoints can spend their assertions on the parts
 * that DO need a database (who may save, which company's ladder is read)
 * instead of re-deriving arithmetic through a controller.
 *
 * Owner decisions 2026-09-24 — choices 1ก / 2ก / 3ก.
 */
class AgentRankLadderInspectorTest extends TestCase
{
    private AgentRankLadderInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = new AgentRankLadderInspector;
    }

    /**
     * @return array{volume_threshold: int, rate_type: CommissionRateType, rate_value: int, is_breakaway_rank: bool}
     */
    private function rung(int $threshold, int $rateValue, bool $breakaway = false, ?CommissionRateType $type = null): array
    {
        return [
            'volume_threshold' => $threshold,
            'rate_type' => $type ?? CommissionRateType::Percentage,
            'rate_value' => $rateValue,
            'is_breakaway_rank' => $breakaway,
        ];
    }

    /** The UAT ladder — 5% / 12% / 20% (breakaway) / 25%, every rung well formed. */
    private function uatLadder(): array
    {
        return [
            $this->rung(0, 500),
            $this->rung(5_000_000, 1_200),
            $this->rung(20_000_000, 2_000, breakaway: true),
            $this->rung(50_000_000, 2_500),
        ];
    }

    // --- Nothing to say ---

    public function test_an_empty_ladder_reports_nothing_at_all(): void
    {
        /*
         * Not "no entry rank". A company on Stairstep with no ranks is
         * already reported by CommissionReadinessService as a missing plan
         * structure, and a second finding in a second vocabulary about the
         * same gap is how an amber banner turns into wallpaper.
         */
        $this->assertSame([], $this->inspector->inspect([]));
    }

    public function test_a_well_formed_ladder_reports_only_the_ranks_above_the_breakaway_rank(): void
    {
        $findings = $this->inspector->inspect($this->uatLadder());

        // The only observation the UAT ladder earns, and it is advisory:
        // ขั้นผู้อำนวยการ sits above the breakaway rung, so it is paid only
        // while its downline has not broken away yet (problem 5.5).
        $this->assertSame([AgentRankLadderInspector::RANKS_ABOVE_BREAKAWAY => 1], $findings);
        $this->assertSame([], $this->inspector->blocking($this->uatLadder()));
    }

    // --- Mixed rate types (problem 5.4) ---

    public function test_a_ladder_priced_in_two_units_is_reported(): void
    {
        $findings = $this->inspector->inspect([
            $this->rung(0, 500),
            $this->rung(5_000_000, 50_000, type: CommissionRateType::FixedSatang),
        ]);

        $this->assertSame(2, $findings[AgentRankLadderInspector::MIXED_RATE_TYPES]);
    }

    public function test_a_mixed_ladder_is_not_also_accused_of_mispriced_rates(): void
    {
        /*
         * 500 basis points beside 50,000 satang would read as "the higher
         * rung is priced far above the lower" to a naive comparison, and as
         * "far below" if the two were swapped — both meaningless. Reporting
         * either would send the admin to fix a second thing that resolves
         * itself the moment they fix the first.
         */
        $findings = $this->inspector->inspect([
            $this->rung(0, 50_000, type: CommissionRateType::FixedSatang),
            $this->rung(5_000_000, 500),
        ]);

        $this->assertArrayNotHasKey(AgentRankLadderInspector::RATE_NOT_INCREASING, $findings);
    }

    // --- Rates that do not rise with volume ---

    public function test_a_higher_threshold_priced_below_a_lower_one_is_reported(): void
    {
        $findings = $this->inspector->inspect([
            $this->rung(0, 500),
            $this->rung(5_000_000, 300),
        ]);

        $this->assertSame(1, $findings[AgentRankLadderInspector::RATE_NOT_INCREASING]);
    }

    public function test_two_rungs_on_the_same_rate_count_as_not_rising(): void
    {
        /*
         * The differential is exactly 0, and "never a $0 ledger row" turns
         * that into no row at all — the same silence as a negative
         * differential, so it is the same finding. A ladder that looks like
         * a promotion and pays like nothing is the failure this catches.
         */
        $findings = $this->inspector->inspect([
            $this->rung(0, 500),
            $this->rung(5_000_000, 500),
        ]);

        $this->assertSame(1, $findings[AgentRankLadderInspector::RATE_NOT_INCREASING]);
    }

    public function test_rungs_sharing_a_threshold_do_not_manufacture_a_rate_finding(): void
    {
        // Two rungs at 0 (one of them cheaper) then a proper step up. The
        // duplicate is a finding; the rates themselves do rise.
        $findings = $this->inspector->inspect([
            $this->rung(0, 500),
            $this->rung(0, 300),
            $this->rung(5_000_000, 1_200),
        ]);

        $this->assertSame(2, $findings[AgentRankLadderInspector::DUPLICATE_THRESHOLDS]);
        $this->assertArrayNotHasKey(AgentRankLadderInspector::RATE_NOT_INCREASING, $findings);
    }

    // --- Duplicate thresholds ---

    public function test_two_rungs_on_one_threshold_are_both_counted(): void
    {
        /*
         * recalculateRanks() takes orderByDesc('volume_threshold')->first(),
         * so which of the two an agent lands on is whatever order the
         * database felt like returning. The count is RANKS on a contested
         * threshold rather than contested thresholds, because an admin has
         * to open every one of those rows to decide which survives.
         */
        $findings = $this->inspector->inspect([
            $this->rung(0, 500),
            $this->rung(5_000_000, 1_200),
            $this->rung(5_000_000, 1_500),
        ]);

        $this->assertSame(2, $findings[AgentRankLadderInspector::DUPLICATE_THRESHOLDS]);
    }

    // --- The entry rank (problem 5.3) ---

    public function test_a_ladder_whose_cheapest_rung_still_needs_volume_has_no_entry_rank(): void
    {
        $findings = $this->inspector->inspect([
            $this->rung(5_000_000, 500),
            $this->rung(20_000_000, 1_200),
        ]);

        $this->assertSame(1, $findings[AgentRankLadderInspector::NO_ENTRY_RANK]);
    }

    public function test_no_entry_rank_is_advisory_and_never_blocks_a_save(): void
    {
        /*
         * Blocking it would trap an admin halfway through building a ladder:
         * you cannot add the threshold-0 rank first if adding any rank at
         * all is refused until one exists.
         */
        $blocking = $this->inspector->blocking([$this->rung(5_000_000, 500)]);

        $this->assertArrayNotHasKey(AgentRankLadderInspector::NO_ENTRY_RANK, $blocking);
    }

    // --- Ranks above the breakaway rung (problem 5.5) ---

    public function test_ranks_above_the_breakaway_rung_are_counted_by_threshold(): void
    {
        // sort_order is display order only — recalculateRanks() assigns
        // purely by volume_threshold, so "above" has to mean "needs more
        // volume than the breakaway rung does".
        $findings = $this->inspector->inspect([
            $this->rung(0, 500),
            $this->rung(5_000_000, 1_200, breakaway: true),
            $this->rung(20_000_000, 2_000),
            $this->rung(50_000_000, 2_500),
        ]);

        $this->assertSame(2, $findings[AgentRankLadderInspector::RANKS_ABOVE_BREAKAWAY]);
    }

    public function test_a_ladder_with_no_breakaway_rung_reports_none_above_it(): void
    {
        $findings = $this->inspector->inspect([
            $this->rung(0, 500),
            $this->rung(5_000_000, 1_200),
        ]);

        $this->assertArrayNotHasKey(AgentRankLadderInspector::RANKS_ABOVE_BREAKAWAY, $findings);
    }

    // --- The non-regression rule (owner choice 3ก) ---

    public function test_making_a_clean_ladder_mixed_is_a_regression(): void
    {
        $before = $this->uatLadder();
        $after = [...$before, $this->rung(80_000_000, 90_000, type: CommissionRateType::FixedSatang)];

        $this->assertArrayHasKey(
            AgentRankLadderInspector::MIXED_RATE_TYPES,
            $this->inspector->regressions($before, $after),
        );
    }

    public function test_an_already_broken_ladder_may_still_be_edited(): void
    {
        /*
         * ═══ THE ANTI-DEADLOCK CASE — THE WHOLE POINT OF CHOICE 3ก ═══
         *
         * A company holding a mixed-type ladder renames a rung, or nudges a
         * threshold. The result is exactly as broken as before and not one
         * bit worse. A strict "the result must be valid" guard would refuse
         * this, and every other edit on the path to fixing it, leaving the
         * admin staring at a form that cannot be saved with no way to start.
         */
        $before = [
            $this->rung(0, 500),
            $this->rung(5_000_000, 50_000, type: CommissionRateType::FixedSatang),
        ];
        $after = [
            $this->rung(0, 500),
            $this->rung(6_000_000, 50_000, type: CommissionRateType::FixedSatang),
        ];

        $this->assertSame([], $this->inspector->regressions($before, $after));
    }

    public function test_fixing_a_broken_ladder_is_never_a_regression(): void
    {
        $before = [
            $this->rung(0, 500),
            $this->rung(5_000_000, 50_000, type: CommissionRateType::FixedSatang),
        ];
        $after = [
            $this->rung(0, 500),
            $this->rung(5_000_000, 1_200),
        ];

        $this->assertSame([], $this->inspector->regressions($before, $after));
    }

    public function test_adding_a_second_rung_on_an_occupied_threshold_is_a_regression(): void
    {
        $before = $this->uatLadder();
        $after = [...$before, $this->rung(5_000_000, 1_300)];

        $regressions = $this->inspector->regressions($before, $after);

        $this->assertSame(2, $regressions[AgentRankLadderInspector::DUPLICATE_THRESHOLDS]);
    }

    public function test_advisory_findings_never_appear_as_regressions(): void
    {
        // Deleting the entry rung raises NO_ENTRY_RANK from 0 to 1 — a real
        // deterioration, and still not one the save path refuses, because
        // the admin may be about to re-add it at a different rate.
        $before = [$this->rung(0, 500), $this->rung(5_000_000, 1_200)];
        $after = [$this->rung(1_000_000, 500), $this->rung(5_000_000, 1_200)];

        $this->assertSame([], $this->inspector->regressions($before, $after));
    }

    // --- Copy ---

    public function test_every_finding_has_thai_copy_of_its_own(): void
    {
        $codes = [
            AgentRankLadderInspector::MIXED_RATE_TYPES,
            AgentRankLadderInspector::RATE_NOT_INCREASING,
            AgentRankLadderInspector::DUPLICATE_THRESHOLDS,
            AgentRankLadderInspector::NO_ENTRY_RANK,
            AgentRankLadderInspector::RANKS_ABOVE_BREAKAWAY,
        ];

        foreach ($codes as $code) {
            // A label that falls through to the default returns the bare
            // code — the failure mode where a new finding ships with no copy
            // and the banner shows an English slug to a Thai admin.
            $this->assertNotSame($code, $this->inspector->label($code, 2), "missing Thai copy for {$code}");
        }
    }

    public function test_the_save_error_says_it_refuses_the_change_for_being_worse(): void
    {
        $messages = $this->inspector->regressionMessages([AgentRankLadderInspector::MIXED_RATE_TYPES => 2]);

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('แย่ลงกว่าเดิม', $messages[0]);
        $this->assertStringContainsString('ปนกัน', $messages[0]);
    }
}
