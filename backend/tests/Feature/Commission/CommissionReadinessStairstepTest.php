<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Models\AgentRank;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\Commission\AgentRankLadderInspector;
use App\Services\Commission\CommissionReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * THE FIVE SILENT FAILURES OF THE STAIRSTEP PLAN, MADE VISIBLE.
 *
 * Owner question, 2026-09-24: "เราจะแก้ปัญหาระบบนี้ของเราอย่างไร". The
 * answer for four of the five was not new arithmetic — the engine is right
 * — it was that a company can be configured into these states, be paid
 * money under them, and never be told. This file is the proof that it is
 * now told.
 *
 * ═══ WHY THEY LIVE ON THIS BANNER ═══
 *
 * Because it is the banner that already carries "your commission setup is
 * incomplete" to every admin page. The alternative — a warning on the rank
 * screen alone — reaches only the person who already went looking, which
 * is never the person who needs it.
 *
 * ═══ AND WHY EVERY ONE OF THEM IS AMBER ═══
 *
 * CommissionReadinessService reserves red for one sentence being literally
 * true: a deal closing right now pays NOBODY. In all five the agent who
 * closed the deal is paid — it is the chain ABOVE them that goes quiet. A
 * red banner that also means "something upstream could be tidier" stops
 * being read, and being ignored is the one failure this feature cannot
 * survive.
 */
class CommissionReadinessStairstepTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/commission-readiness';

    /** A Stairstep company with one sellable product and a live 5% seller rate. */
    private function stairstepCompany(int $sellerRateValue = 500): Company
    {
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::StairstepBreakaway->value,
        ]);

        // category_id/brand_id null on purpose — ProductFactory's defaults
        // each spin up a company of their own, which would change what the
        // aggregate across companies means.
        Product::factory()->for($company)->create(['category_id' => null, 'brand_id' => null]);

        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $sellerRateValue,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);

        return $company;
    }

    private function rank(Company $company, string $name, int $threshold, int $rateValue, int $sortOrder, bool $breakaway = false, ?CommissionRateType $type = null): AgentRank
    {
        return AgentRank::factory()->create([
            'company_id' => $company->id,
            'name' => $name,
            'volume_threshold' => $threshold,
            'sort_order' => $sortOrder,
            'rate_type' => $type ?? CommissionRateType::Percentage,
            'rate_value' => $rateValue,
            'is_breakaway_rank' => $breakaway,
        ]);
    }

    /** The UAT ladder, minus the rung above the breakaway rank. */
    private function cleanLadder(Company $company): void
    {
        $this->rank($company, 'Entry', 0, 500, 1);
        $this->rank($company, 'Leader', 5_000_000, 1_200, 2);
    }

    private function readinessFor(Company $company): array
    {
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        return $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->json();
    }

    /** @return array<string, array{code: string, label: string, count: int}> */
    private function issuesByCode(array $payload): array
    {
        return collect($payload['issues'])->keyBy('code')->all();
    }

    // --- Silence when there is nothing to say ---

    public function test_a_well_formed_stairstep_company_raises_no_ladder_issues(): void
    {
        $company = $this->stairstepCompany();
        $this->cleanLadder($company);

        $payload = $this->readinessFor($company);

        $this->assertSame('ready', $payload['state']);
        $this->assertSame([], $payload['issues']);
    }

    public function test_a_company_on_another_plan_is_never_asked_about_its_ladder(): void
    {
        /*
         * agent_ranks is shared with Generation, and a Unilevel company can
         * hold a legacy ladder from before a plan switch. Neither pays a
         * differential, so reporting its shape would be a warning about a
         * table nothing reads — the fastest way to teach an admin that
         * amber means nothing.
         */
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel]);
        Product::factory()->for($company)->create(['category_id' => null, 'brand_id' => null]);
        $this->rank($company, 'Broken', 5_000_000, 90_000, 1, type: CommissionRateType::FixedSatang);

        $codes = array_keys($this->issuesByCode($this->readinessFor($company)));

        $this->assertSame([], array_filter($codes, fn (string $code) => str_starts_with($code, 'stairstep_')));
    }

    public function test_a_stairstep_company_with_no_ladder_at_all_is_reported_once_not_twice(): void
    {
        // unsetStructuralPlans() already says "โครงสร้างยังไม่ได้ตั้งค่า".
        // Adding "no entry rank" beside it would be the same gap counted in
        // two vocabularies.
        $company = $this->stairstepCompany();

        $codes = array_keys($this->issuesByCode($this->readinessFor($company)));

        $this->assertContains('plan_structure_missing', $codes);
        $this->assertNotContains('stairstep_no_entry_rank', $codes);
    }

    // --- The ladder's own findings ---

    public function test_a_mixed_rate_type_ladder_is_reported(): void
    {
        $company = $this->stairstepCompany();
        $this->rank($company, 'Entry', 0, 500, 1);
        $this->rank($company, 'Leader', 5_000_000, 90_000, 2, type: CommissionRateType::FixedSatang);

        $issues = $this->issuesByCode($this->readinessFor($company));

        $this->assertArrayHasKey('stairstep_mixed_rate_types', $issues);
        $this->assertStringContainsString('ปนกัน', $issues['stairstep_mixed_rate_types']['label']);
    }

    public function test_a_ladder_with_no_entry_rung_is_reported(): void
    {
        $company = $this->stairstepCompany();
        $this->rank($company, 'Leader', 5_000_000, 1_200, 1);

        $issues = $this->issuesByCode($this->readinessFor($company));

        $this->assertArrayHasKey('stairstep_no_entry_rank', $issues);
        $this->assertStringContainsString('฿0', $issues['stairstep_no_entry_rank']['label']);
    }

    public function test_two_rungs_on_one_threshold_are_reported(): void
    {
        $company = $this->stairstepCompany();
        $this->cleanLadder($company);
        $this->rank($company, 'Leader II', 5_000_000, 1_500, 3);

        $issues = $this->issuesByCode($this->readinessFor($company));

        $this->assertSame(2, $issues['stairstep_duplicate_thresholds']['count']);
    }

    public function test_rates_that_stop_rising_are_reported(): void
    {
        $company = $this->stairstepCompany();
        $this->rank($company, 'Entry', 0, 500, 1);
        $this->rank($company, 'Leader', 5_000_000, 300, 2);

        $issues = $this->issuesByCode($this->readinessFor($company));

        $this->assertArrayHasKey('stairstep_rate_not_increasing', $issues);
    }

    public function test_rungs_above_the_breakaway_rank_are_reported(): void
    {
        $company = $this->stairstepCompany();
        $this->rank($company, 'Entry', 0, 500, 1);
        $this->rank($company, 'Manager', 5_000_000, 2_000, 2, breakaway: true);
        $this->rank($company, 'Director', 20_000_000, 2_500, 3);

        $issues = $this->issuesByCode($this->readinessFor($company));

        $this->assertSame(1, $issues['stairstep_ranks_above_breakaway']['count']);
    }

    // --- The agents, which the ladder cannot see ---

    public function test_agents_with_no_rank_are_counted_and_told_what_happens_to_them(): void
    {
        $company = $this->stairstepCompany();
        $this->cleanLadder($company);
        User::factory()->agent()->count(3)->create(['company_id' => $company->id]);

        $issues = $this->issuesByCode($this->readinessFor($company));

        $this->assertSame(3, $issues['stairstep_unranked_agents']['count']);
        // With an entry rung they are simply priced at it (owner choice 2ก)
        // — a note, not an alarm.
        $this->assertStringContainsString('ขั้นเกณฑ์ ฿0 ไปก่อน', $issues['stairstep_unranked_agents']['label']);
    }

    public function test_the_same_agents_are_a_different_sentence_when_there_is_no_entry_rung(): void
    {
        /*
         * The COUNT is identical; the consequence is not. Without a rung to
         * borrow, every sale these three make pays their manager nothing,
         * and an admin who read the softer wording would have no idea.
         */
        $company = $this->stairstepCompany();
        $this->rank($company, 'Leader', 5_000_000, 1_200, 1);
        User::factory()->agent()->count(3)->create(['company_id' => $company->id]);

        $issues = $this->issuesByCode($this->readinessFor($company));

        $this->assertStringContainsString('ไม่จ่ายส่วนต่างให้หัวหน้าเลย', $issues['stairstep_unranked_agents']['label']);
    }

    // --- The seller rate (owner choice 1ก) ---

    public function test_a_seller_rate_that_disagrees_with_the_ladder_states_the_resulting_total(): void
    {
        /*
         * THE NUMBER IS THE WHOLE POINT OF THIS WARNING.
         *
         * Entry rung 5%, top rung 12%, seller rate 10%. The differentials
         * telescope from the entry rung upward, so the chain pays
         * 10 + (12 - 5) = 17%, not the 12% the ladder's top rung implies.
         * Telling an admin "these do not match" would make them hunt for
         * the consequence; telling them 17% is the consequence.
         */
        $company = $this->stairstepCompany(sellerRateValue: 1_000);
        $this->cleanLadder($company);

        $issues = $this->issuesByCode($this->readinessFor($company));
        $label = $issues['stairstep_seller_rate_mismatch']['label'];

        $this->assertStringContainsString('10%', $label);
        $this->assertStringContainsString('5%', $label);
        $this->assertStringContainsString('17%', $label);
        $this->assertStringContainsString('12%', $label);
    }

    public function test_a_seller_rate_equal_to_the_entry_rung_says_nothing(): void
    {
        // The plan's promise holds exactly here: total = top rung. Silence
        // is the correct output.
        $company = $this->stairstepCompany(sellerRateValue: 500);
        $this->cleanLadder($company);

        $this->assertArrayNotHasKey('stairstep_seller_rate_mismatch', $this->issuesByCode($this->readinessFor($company)));
    }

    public function test_a_fixed_satang_seller_rate_is_not_compared_against_a_percentage_ladder(): void
    {
        /*
         * "Is ฿900 more than 5%" has no answer without a sale to apply them
         * to, and inventing one to put a figure on the banner is exactly the
         * guessing this Service's mirror rule exists to prevent.
         */
        $company = $this->stairstepCompany();
        CommissionRule::where('company_id', $company->id)->update([
            'rate_type' => CommissionRateType::FixedSatang->value,
            'rate_value' => 90_000,
        ]);
        $this->cleanLadder($company);

        $this->assertArrayNotHasKey('stairstep_seller_rate_mismatch', $this->issuesByCode($this->readinessFor($company)));
    }

    // --- How the banner behaves ---

    public function test_a_ladder_problem_is_amber_and_points_at_step_two(): void
    {
        $company = $this->stairstepCompany();
        $this->rank($company, 'Entry', 0, 500, 1);
        $this->rank($company, 'Leader', 5_000_000, 500, 2);

        $payload = $this->readinessFor($company);

        // Never red: the seller who closed the deal IS paid.
        $this->assertSame('incomplete', $payload['state']);
        // Step 2 is where the ladder is edited.
        $this->assertSame(2, $payload['blocking_step']);
    }

    public function test_a_missing_rate_still_outranks_a_broken_ladder(): void
    {
        /*
         * A product with no rate pays NOBODY, which makes every observation
         * about the chain above irrelevant until it is fixed. The banner has
         * one job — hand somebody to the right step — so the order matters.
         */
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::StairstepBreakaway->value,
        ]);
        Product::factory()->for($company)->create(['category_id' => null, 'brand_id' => null]);
        $this->rank($company, 'Entry', 0, 500, 1);
        $this->rank($company, 'Leader', 5_000_000, 500, 2);

        $payload = $this->readinessFor($company);

        $this->assertSame('missing', $payload['state']);
        $this->assertSame(3, $payload['blocking_step']);
    }

    public function test_the_banner_and_the_save_path_agree_about_what_is_broken(): void
    {
        /*
         * Both sides ask AgentRankLadderInspector. This asserts the wiring
         * rather than the arithmetic: a finding the banner reports must
         * carry the inspector's own Thai copy, so an admin is never refused
         * a save for a reason the banner phrased differently.
         */
        $company = $this->stairstepCompany();
        $this->rank($company, 'Entry', 0, 500, 1);
        $this->rank($company, 'Leader', 5_000_000, 90_000, 2, type: CommissionRateType::FixedSatang);

        $issues = $this->issuesByCode(app(CommissionReadinessService::class)->forActor(
            User::factory()->superAdmin()->create(),
            (int) $company->id,
        ));

        $inspector = app(AgentRankLadderInspector::class);

        $this->assertSame(
            $inspector->label(AgentRankLadderInspector::MIXED_RATE_TYPES, 2),
            $issues['stairstep_mixed_rate_types']['label'],
        );
    }
}
