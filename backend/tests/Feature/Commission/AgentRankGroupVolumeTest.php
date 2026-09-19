<?php

namespace Tests\Feature\Commission;

use App\Enums\AgentRankVolumeScope;
use App\Enums\CommissionBasis;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Enums\PromotionStatus;
use App\Models\AgentRank;
use App\Models\AgentRankSetting;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductPricePromotion;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Commission\StairstepCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHOSE volume, and measured at WHAT figure, decides a rank.
 *
 * ═══ THE TWO DEFECTS THIS COVERS ═══
 *
 * 1. Rank qualified on the agent's PERSONAL sales only, with no way to
 *    count the team's. Stairstep pays the DIFFERENCE between a manager's
 *    rank rate and their downline's, so a leader who recruits instead of
 *    selling falls behind the people under them and the differential goes
 *    to zero — the plan stops paying the exact person it exists to pay.
 *    Generation, which counts breakaway legs, then has nothing to count
 *    either, because a leader can never reach a breakaway rank.
 *
 * 2. Volume was summed from `products.price_satang` — the list price
 *    TODAY. A promoted sale counted at full price, a PV company qualified
 *    ranks in baht while paying commission in points, and editing a
 *    product's price silently re-ranked every historical sale of it.
 *
 * The first is a switch whose default is today's behaviour (BR-7: which
 * one a company promises its agents is theirs to decide). The second is
 * a bug fix and applies to everybody — which is why the tests for it are
 * written on a personal-scope company, where nothing else changed.
 */
class AgentRankGroupVolumeTest extends TestCase
{
    use RefreshDatabase;

    private function company(AgentRankVolumeScope $scope, CommissionBasis $basis = CommissionBasis::Price): Company
    {
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::StairstepBreakaway->value,
            'commission_basis' => $basis->value,
        ]);

        AgentRankSetting::factory()->create([
            'company_id' => $company->id,
            'trailing_window_days' => 90,
            'volume_scope' => $scope,
        ]);

        return $company;
    }

    private function certify(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::firstOrCreate(
            ['user_id' => $agent->id, 'cert_tier_id' => $tier->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );

        return $tier;
    }

    /** A rank nobody clears without $threshold satang of volume. */
    private function rankAt(Company $company, int $threshold, string $name = 'Silver'): AgentRank
    {
        return AgentRank::factory()->create([
            'company_id' => $company->id,
            'name' => $name,
            'volume_threshold' => $threshold,
            'sort_order' => 2,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 500,
        ]);
    }

    private function sell(Company $company, User $seller, Product $product): Referral
    {
        $tier = $this->certify($seller, $company);

        $alreadyRuled = CommissionRule::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('cert_tier_id', $tier->id)
            ->where('product_id', $product->id)
            ->exists();

        if (! $alreadyRuled) {
            CommissionRule::factory()->create([
                'company_id' => $company->id,
                'cert_tier_id' => $tier->id,
                'product_id' => $product->id,
                'rate_type' => CommissionRateType::Percentage,
                'rate_value' => 100,
            ]);
        }

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

        return $referral;
    }

    private function recalculate(): void
    {
        app(StairstepCommissionService::class)->recalculateRanks();
    }

    // ── The promise that nothing moved ──────────────────────────────────────

    public function test_a_company_that_never_changes_the_scope_qualifies_on_personal_sales_only(): void
    {
        /*
         * The whole safety argument for deploying this, and first on
         * purpose. Every existing company is on 'personal', which is the
         * column's default; a downline's sale must not reach their manager's
         * rank unless the company asked for it.
         */
        $company = $this->company(AgentRankVolumeScope::Personal);
        $silver = $this->rankAt($company, 900_000);
        $product = Product::factory()->for($company)->create(['price_satang' => 1_000_000]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);

        $this->sell($company, $seller, $product);
        $this->recalculate();

        $this->assertSame($silver->id, $seller->fresh()->current_rank_id, 'the seller qualifies on their own sale');
        $this->assertNull($manager->fresh()->current_rank_id, 'the manager sold nothing and must stay unranked');
    }

    // ── Group volume ────────────────────────────────────────────────────────

    public function test_with_group_scope_a_managers_rank_counts_their_downlines_sales(): void
    {
        $company = $this->company(AgentRankVolumeScope::Group);
        $silver = $this->rankAt($company, 900_000);
        $product = Product::factory()->for($company)->create(['price_satang' => 1_000_000]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);

        $this->sell($company, $seller, $product);
        $this->recalculate();

        $this->assertSame($silver->id, $manager->fresh()->current_rank_id);
    }

    public function test_group_volume_is_the_whole_subtree_plus_the_agents_own(): void
    {
        // leader -> mid -> junior. Nobody's single sale clears the rank;
        // the leader's does only if all three roll up to them.
        $company = $this->company(AgentRankVolumeScope::Group);
        $silver = $this->rankAt($company, 2_500_000);
        $product = Product::factory()->for($company)->create(['price_satang' => 1_000_000]);

        $leader = User::factory()->agent()->create(['company_id' => $company->id]);
        $mid = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $leader->id]);
        $junior = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $mid->id]);

        $this->sell($company, $leader, $product);
        $this->sell($company, $mid, $product);
        $this->sell($company, $junior, $product);

        $this->recalculate();

        // leader = 3,000,000 (own + mid + junior) → clears.
        $this->assertSame($silver->id, $leader->fresh()->current_rank_id);
        // mid = 2,000,000 (own + junior) → does not.
        $this->assertNull($mid->fresh()->current_rank_id);
        // junior = 1,000,000 (own only) → does not.
        $this->assertNull($junior->fresh()->current_rank_id);
    }

    public function test_group_volume_stops_at_the_company_boundary(): void
    {
        // BR-6. A manager_id pointing at another tenant's user cannot
        // happen through the write path, but the roll-up must not be the
        // thing that trusts it: the other company's agent is not in this
        // company's map, so the walk ends.
        $company = $this->company(AgentRankVolumeScope::Group);
        $this->rankAt($company, 1);
        $other = $this->company(AgentRankVolumeScope::Group);

        $outsider = User::factory()->agent()->create(['company_id' => $other->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $outsider->id]);

        $product = Product::factory()->for($company)->create(['price_satang' => 1_000_000]);
        $this->sell($company, $seller, $product);

        $this->recalculate();

        $this->assertNull($outsider->fresh()->current_rank_id);
    }

    public function test_a_cyclic_manager_chain_does_not_hang_the_roll_up(): void
    {
        // A cycle cannot be written through UserService::assertValidManager(),
        // but a restored backup or a manual UPDATE can hold one, and this
        // job runs unattended.
        $company = $this->company(AgentRankVolumeScope::Group);
        $this->rankAt($company, 1);
        $product = Product::factory()->for($company)->create(['price_satang' => 1_000_000]);

        $a = User::factory()->agent()->create(['company_id' => $company->id]);
        $b = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $a->id]);
        $a->forceFill(['manager_id' => $b->id])->save();

        $this->sell($company, $b, $product);
        $this->recalculate();

        $this->assertNotNull($a->fresh()->current_rank_id);
        $this->assertNotNull($b->fresh()->current_rank_id);
    }

    // ── What the volume is measured at ──────────────────────────────────────

    public function test_a_promoted_sale_counts_at_what_the_customer_actually_paid(): void
    {
        // Personal scope — this is the bug fix, and it applies to every
        // company whether or not they ever touch volume_scope.
        $company = $this->company(AgentRankVolumeScope::Personal);
        $this->rankAt($company, 900_000);
        $product = Product::factory()->for($company)->create(['price_satang' => 1_000_000]);
        ProductPricePromotion::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'discounted_price_satang' => 800_000,
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->sell($company, $seller, $product);
        $this->recalculate();

        // 800,000 paid, threshold 900,000 → unranked. The old query summed
        // the 1,000,000 list price and would have promoted them on money
        // the company never took.
        $this->assertNull($seller->fresh()->current_rank_id);
    }

    public function test_a_pv_company_qualifies_ranks_in_points_not_in_baht(): void
    {
        // Otherwise the two ladders measure different things: commission in
        // points, rank in baht. A company adopts PV precisely so that the
        // price moving does not move what an agent earns or reaches.
        $company = $this->company(AgentRankVolumeScope::Personal, CommissionBasis::PointValue);
        // Two rungs, placed so the two candidate figures land on different
        // ones: 400,000 PV reaches Bronze, 1,000,000 baht would reach
        // Silver. Asserting the exact rank is what makes this test bite —
        // "somebody got ranked" would pass either way.
        $bronze = $this->rankAt($company, 350_000, 'Bronze');
        $this->rankAt($company, 500_000, 'Silver');

        $product = Product::factory()->for($company)->create([
            'price_satang' => 1_000_000,
            'pv_satang' => 400_000,
        ]);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->sell($company, $seller, $product);
        $this->recalculate();

        $this->assertSame(
            $bronze->id,
            $seller->fresh()->current_rank_id,
            'a PV company must measure the ladder in the same points it pays commission in',
        );
    }

    public function test_editing_a_products_price_does_not_re_rank_a_sale_already_made(): void
    {
        /*
         * The BR-4 argument, one table over. Ranks are not a ledger, but
         * they decide the differential that WRITES ledger rows — so a price
         * edit today changing a rank earned last month changes what a
         * manager is paid on tomorrow's sale. The sale's own snapshot is
         * the only figure that cannot move underneath it.
         */
        $company = $this->company(AgentRankVolumeScope::Personal);
        $this->rankAt($company, 900_000);
        $product = Product::factory()->for($company)->create(['price_satang' => 1_000_000]);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->sell($company, $seller, $product);

        $product->forceFill(['price_satang' => 100_000])->save();

        $this->recalculate();

        $this->assertNotNull(
            $seller->fresh()->current_rank_id,
            'the sale closed at 1,000,000 and must still count as 1,000,000',
        );
    }

    public function test_a_split_sale_counts_once_not_twice(): void
    {
        // TASK-026 writes two Direct ledger rows for one referral, both
        // carrying the same snapshot of the sale's value — a split divides
        // the commission, not the sale.
        $company = $this->company(AgentRankVolumeScope::Personal);
        $this->rankAt($company, 1_500_000);
        $product = Product::factory()->for($company)->create(['price_satang' => 1_000_000]);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $coAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->certify($coAgent, $company);

        $referral = $this->sell($company, $seller, $product);
        $referral->forceFill(['co_agent_id' => $coAgent->id, 'split_percentage' => 50])->save();

        $this->recalculate();

        $this->assertNull(
            $seller->fresh()->current_rank_id,
            'one 1,000,000 sale, however its commission was split, is 1,000,000 of volume',
        );
    }

    // ── The setting ─────────────────────────────────────────────────────────

    public function test_the_scope_is_saved_through_the_settings_endpoint(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->putJson('/api/v1/agent-rank-settings', [
                'company_id' => $company->id,
                'trailing_window_days' => 90,
                'recalculation_frequency' => 'weekly',
                'volume_scope' => AgentRankVolumeScope::Group->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.volume_scope', 'group');

        $this->assertDatabaseHas('agent_rank_settings', [
            'company_id' => $company->id,
            'volume_scope' => 'group',
        ]);
    }

    public function test_an_unrecognised_scope_is_refused(): void
    {
        $company = Company::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/agent-rank-settings', [
                'company_id' => $company->id,
                'trailing_window_days' => 90,
                'recalculation_frequency' => 'weekly',
                'volume_scope' => 'whole_company',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('volume_scope');
    }

    public function test_a_value_the_enum_does_not_recognise_degrades_to_personal(): void
    {
        /*
         * A hand-edited row, a half-rolled-back migration, a future case
         * removed from the enum. The cast would throw — on a scheduled job
         * nobody is watching, which would stop rank recalculation for every
         * tenant in the sweep, not just this one. The fail-safe answer is
         * the behaviour the code had before the column existed.
         */
        $company = Company::factory()->create();
        $setting = AgentRankSetting::factory()->create(['company_id' => $company->id]);
        AgentRankSetting::withoutGlobalScopes()
            ->where('id', $setting->id)
            ->update(['volume_scope' => 'whole_company']);

        $this->assertSame(AgentRankVolumeScope::Personal, $setting->fresh()->volumeScope());

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/agent-rank-settings?company_id={$company->id}")
            ->assertOk()
            ->assertJsonPath('data.volume_scope', 'personal');
    }
}
