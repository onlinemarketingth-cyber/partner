<?php

namespace Tests\Feature\Commission;

use App\Enums\AgentRankVolumeScope;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\AgentRank;
use App\Models\AgentRankSetting;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Commission\StairstepCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A BROKEN-AWAY LEG STOPS COUNTING TOWARD ITS FORMER UPLINE.
 *
 * ═══ THE QUESTION THIS ANSWERS ═══
 *
 * `rolledUpThroughTheManagerChain()` carried a `TODO: CONFIRM` from the day
 * group volume was built: does a leg whose holder has reached a breakaway
 * rank still add to the volume of the manager above them?
 *
 * Owner decision, 2026-09-24: no. `payDifferentialOverride()` already stops
 * PAYING past such a leg, and counting what you are not paid for is the
 * arrangement that lets somebody who recruited one large organisation years
 * ago sit at the top rung forever without doing anything since. Breakaway
 * means the leg is its own organisation — for the money and for the volume.
 *
 * ═══ WHERE THE CUT FALLS ═══
 *
 * On the CHILD, exactly as in the payout walk: the pair severed is (breakaway
 * holder, their manager). So the holder keeps their own sales AND everything
 * under them; only the manager above receives none of it.
 *
 * ═══ AND WHY GENERATION IS IN THIS FILE ═══
 *
 * recalculateRanks() sweeps every company holding agent_rank_settings, not
 * every Stairstep company, and GenerationCommissionService draws its
 * generation boundaries at whoever holds a breakaway rank. A Generation
 * company on group scope therefore feels this change too — which is the kind
 * of cross-plan consequence that is invisible until something is asserted
 * about it.
 */
class BreakawayGroupVolumeTest extends TestCase
{
    use RefreshDatabase;

    private const SALE_SATANG = 1_000_000; // ฿10,000 per sale

    private function company(CommissionPlanType $plan = CommissionPlanType::StairstepBreakaway): Company
    {
        $company = Company::factory()->create(['commission_plan_type' => $plan->value]);

        AgentRankSetting::factory()->create([
            'company_id' => $company->id,
            'trailing_window_days' => 90,
            'volume_scope' => AgentRankVolumeScope::Group,
        ]);

        return $company;
    }

    private function rank(Company $company, string $name, int $threshold, int $sortOrder, bool $breakaway = false): AgentRank
    {
        return AgentRank::factory()->create([
            'company_id' => $company->id,
            'name' => $name,
            'volume_threshold' => $threshold,
            'sort_order' => $sortOrder,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 500 * $sortOrder,
            'is_breakaway_rank' => $breakaway,
        ]);
    }

    private function setRank(User $user, AgentRank $rank): void
    {
        $user->forceFill(['current_rank_id' => $rank->id])->save();
    }

    private function sell(Company $company, User $seller): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::firstOrCreate(
            ['user_id' => $seller->id, 'cert_tier_id' => $tier->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => self::SALE_SATANG]);
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 100,
        ]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id,
            'product_id' => $product->id, 'branch' => 'Silom', 'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered, 'meeting_number' => null, 'submitted_at' => now(),
        ]);

        while ($referral->current_stage !== PipelineStage::CompletePayment) {
            $this->actingAs($seller)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
            $referral->refresh();
        }
    }

    private function recalculate(): void
    {
        app(StairstepCommissionService::class)->recalculateRanks();
    }

    public function test_a_breakaway_legs_sales_no_longer_reach_the_manager_above_it(): void
    {
        /*
         * director → manager(BREAKAWAY) → seller
         *
         * The seller's ฿10,000 belongs to the manager's organisation now.
         * Before this change it kept climbing and handed the director a rank
         * they had not worked for.
         */
        $company = $this->company();
        $entry = $this->rank($company, 'Entry', 0, 1);
        $breakawayRank = $this->rank($company, 'Manager', 500_000, 2, breakaway: true);
        $top = $this->rank($company, 'Director', 900_000, 3);

        $director = User::factory()->agent()->create(['company_id' => $company->id]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $director->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);

        $this->setRank($manager, $breakawayRank);
        $this->sell($company, $seller);

        $this->recalculate();

        // The director cleared nothing of their own, so they fall to the
        // threshold-0 rung rather than riding the leg below them to the top.
        $this->assertSame($entry->id, $director->fresh()->current_rank_id);
        $this->assertNotSame($top->id, $director->fresh()->current_rank_id);
    }

    public function test_the_breakaway_holder_keeps_everything_below_them(): void
    {
        /*
         * The cut is between the holder and the manager ABOVE. Severing the
         * holder from their own downline instead would be a different plan
         * entirely — and would leave a breakaway leader unable to hold the
         * rank they just earned.
         */
        $company = $this->company();
        $this->rank($company, 'Entry', 0, 1);
        $breakawayRank = $this->rank($company, 'Manager', 500_000, 2, breakaway: true);
        $top = $this->rank($company, 'Director', 900_000, 3);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $sellerA = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $sellerB = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);

        $this->setRank($manager, $breakawayRank);
        $this->sell($company, $sellerA);
        $this->sell($company, $sellerB);

        $this->recalculate();

        // ฿20,000 of team volume still reaches them — enough for the top rung.
        $this->assertSame($top->id, $manager->fresh()->current_rank_id);
    }

    public function test_a_chain_with_no_breakaway_holder_still_rolls_all_the_way_up(): void
    {
        // The regression guard: nothing about ordinary roll-up may change.
        $company = $this->company();
        $this->rank($company, 'Entry', 0, 1);
        $top = $this->rank($company, 'Director', 900_000, 3);

        $director = User::factory()->agent()->create(['company_id' => $company->id]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $director->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);

        $this->sell($company, $seller);
        $this->sell($company, $manager);

        $this->recalculate();

        $this->assertSame($top->id, $director->fresh()->current_rank_id);
    }

    public function test_a_seller_who_is_themselves_breakaway_contributes_to_nobody_above(): void
    {
        /*
         * The edge the payout walk has too: `$childRank?->is_breakaway_rank`
         * is tested on the SELLER first, before any hop. Their own sales stay
         * theirs and climb no further.
         */
        $company = $this->company();
        $entry = $this->rank($company, 'Entry', 0, 1);
        $breakawayRank = $this->rank($company, 'Manager', 500_000, 2, breakaway: true);

        $director = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $director->id]);

        $this->setRank($seller, $breakawayRank);
        $this->sell($company, $seller);

        $this->recalculate();

        $this->assertSame($entry->id, $director->fresh()->current_rank_id);
    }

    public function test_personal_scope_is_untouched_by_any_of_this(): void
    {
        /*
         * The exclusion lives inside the group roll-up. A company counting
         * only what each agent sold themselves has no roll-up to exclude
         * from, and must behave exactly as it did before.
         */
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::StairstepBreakaway->value,
        ]);
        AgentRankSetting::factory()->create([
            'company_id' => $company->id,
            'trailing_window_days' => 90,
            'volume_scope' => AgentRankVolumeScope::Personal,
        ]);

        $entry = $this->rank($company, 'Entry', 0, 1);
        $breakawayRank = $this->rank($company, 'Manager', 500_000, 2, breakaway: true);

        $director = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $director->id]);
        $this->setRank($seller, $breakawayRank);

        $this->sell($company, $seller);
        $this->recalculate();

        // Their own ฿10,000 clears the 500,000-satang rung on its own.
        $this->assertSame($breakawayRank->id, $seller->fresh()->current_rank_id);
        // And the director qualifies on nothing, exactly as before.
        $this->assertSame($entry->id, $director->fresh()->current_rank_id);
    }

    public function test_a_generation_company_is_moved_by_the_same_rule(): void
    {
        /*
         * recalculateRanks() sweeps by "has rank settings", not by plan, and
         * Generation reads `is_breakaway_rank` to find where each generation
         * begins. So this change reaches a plan that never looks at a rank's
         * rate — worth asserting, because nothing else in the Generation
         * tests would notice.
         */
        $company = $this->company(CommissionPlanType::Generation);
        $entry = $this->rank($company, 'Entry', 0, 1);
        $breakawayRank = $this->rank($company, 'Boundary', 500_000, 2, breakaway: true);
        $top = $this->rank($company, 'Top', 900_000, 3);

        $director = User::factory()->agent()->create(['company_id' => $company->id]);
        $manager = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $director->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);

        $this->setRank($manager, $breakawayRank);
        $this->sell($company, $seller);

        $this->recalculate();

        $this->assertSame($entry->id, $director->fresh()->current_rank_id);
        $this->assertNotSame($top->id, $director->fresh()->current_rank_id);
    }

    public function test_a_deactivated_agents_sales_reach_nobody_above_them(): void
    {
        /*
         * ═══ WRITTEN TWICE, AND THE SECOND TIME HONESTLY ═══
         *
         * breakawayHolders() first shipped with `whereNull('deleted_at')` and
         * a confident comment about why. Mutating the filter away left every
         * test green — so it was never exercised, and chasing that turned up
         * the real rule, which is NOT about breakaway at all:
         *
         *   `$managerOf` is built with SoftDeletes ON, so a deactivated agent
         *   has no entry in it. `$managerOf[$sellerId] ?? null` is therefore
         *   null and the roll-up loop never runs — whatever rank they hold.
         *
         * Their sales are still counted by personalVolumesSatang() (it reads
         * `referrals.agent_id` and never asks about the user), so the volume
         * exists; it simply stops with them. The filter decided nothing and
         * is gone.
         *
         * The assertion below is the same either way, which is exactly why it
         * is documented rather than presented as a breakaway test.
         */
        $company = $this->company();
        $entry = $this->rank($company, 'Entry', 0, 1);
        $breakawayRank = $this->rank($company, 'Manager', 500_000, 2, breakaway: true);
        $top = $this->rank($company, 'Director', 900_000, 3);

        $director = User::factory()->agent()->create(['company_id' => $company->id]);
        $gone = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $director->id]);

        $this->setRank($gone, $breakawayRank);
        $this->sell($company, $gone);
        $gone->delete();

        $this->recalculate();

        // Their ฿10,000 is counted, and stops with them — because they are
        // not in the manager map, not because of the rank they hold.
        $this->assertSame($entry->id, $director->fresh()->current_rank_id);
        $this->assertNotSame($top->id, $director->fresh()->current_rank_id);
    }
}
