<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unilevel pays a rate per LEVEL, and stops where the company says.
 *
 * ═══ WHAT THIS REPLACES ═══
 *
 * One rate, paid to every manager up an uncapped chain. Two consequences, and
 * the second is the one that bit:
 *
 *   · It is not how the plan is quoted anywhere — 10/5/3 down three levels is
 *     the shape, not 2% to everybody for ever.
 *   · OverrideDeductionGuard could only defend the seller's commission by
 *     shrinking the RATE (sellerCommission ÷ deepestChain), so the deeper the
 *     company recruited, the lower the rate anybody was allowed to set, for
 *     everybody, retroactively. A compensation plan whose headline number
 *     falls as the organisation grows cannot be run.
 *
 * ═══ WHAT THE FIRST TEST IS FOR ═══
 *
 * `test_a_company_with_no_levelled_rates_pays_exactly_what_it_paid_before`
 * is the whole safety argument for shipping this to live companies, and it is
 * first on purpose. Everything else here is new behaviour nobody has yet; that
 * one is the promise that nobody's existing arithmetic moved.
 */
class UnilevelPerLevelRatesTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $attributes = []): Company
    {
        return Company::factory()->create(array_merge([
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
            'commission_override_mode' => CommissionOverrideMode::Additive->value,
        ], $attributes));
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

    /** A chain of $depth managers above one certified seller, top first. */
    private function chain(Company $company, int $depth): array
    {
        $managers = [];
        $above = null;
        for ($i = $depth; $i >= 1; $i--) {
            $above = User::factory()->agent()->create([
                'company_id' => $company->id,
                'manager_id' => $above?->id,
            ]);
            $this->certify($above, $company);
            $managers[$i] = $above;
        }

        $seller = User::factory()->agent()->create([
            'company_id' => $company->id,
            'manager_id' => $managers[1]->id ?? null,
        ]);

        return [$seller, $managers];
    }

    private function rate(Company $company, int $basisPoints, ?int $level = null): CommissionOverrideRule
    {
        return CommissionOverrideRule::factory()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'level' => $level,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $basisPoints,
        ]);
    }

    private function sell(Company $company, User $seller, int $priceSatang = 1_000_000): void
    {
        $tier = $this->certify($seller, $company);
        $product = Product::factory()->for($company)->create(['price_satang' => $priceSatang]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
        ]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id,
            'product_id' => $product->id, 'branch' => 'Silom', 'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered, 'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->closeSale($referral, $seller);
    }

    private function overrideFor(User $manager): int
    {
        return (int) CommissionLedger::withoutGlobalScopes()
            ->where('agent_id', $manager->id)
            ->where('earned_via', CommissionEarnedVia::Override->value)
            ->sum('amount_satang');
    }

    // ── The promise that nothing moved ──────────────────────────────────────

    public function test_a_company_with_no_levelled_rates_pays_exactly_what_it_paid_before(): void
    {
        $company = $this->company();
        $this->rate($company, 100); // 1%, no level → the catch-all
        [$seller, $managers] = $this->chain($company, 3);

        $this->sell($company, $seller);

        // One rate to every manager, as far as the chain goes — 1% of
        // 1,000,000 to each of the three.
        $this->assertSame(10_000, $this->overrideFor($managers[1]));
        $this->assertSame(10_000, $this->overrideFor($managers[2]));
        $this->assertSame(10_000, $this->overrideFor($managers[3]));
    }

    // ── Per-level rates ─────────────────────────────────────────────────────

    public function test_each_level_is_paid_its_own_rate(): void
    {
        $company = $this->company();
        $this->rate($company, 500, level: 1); // 5%
        $this->rate($company, 300, level: 2); // 3%
        $this->rate($company, 100, level: 3); // 1%
        [$seller, $managers] = $this->chain($company, 3);

        $this->sell($company, $seller);

        $this->assertSame(50_000, $this->overrideFor($managers[1]));
        $this->assertSame(30_000, $this->overrideFor($managers[2]));
        $this->assertSame(10_000, $this->overrideFor($managers[3]));
    }

    public function test_a_catch_all_serves_every_level_nobody_priced(): void
    {
        // Quote the first level explicitly, leave the rest on one number —
        // the arrangement a company reaches for before it has decided the
        // whole ladder, and the reason the catch-all was not dropped.
        $company = $this->company();
        $this->rate($company, 500, level: 1); // 5% at the top
        $this->rate($company, 100);           // 1% everywhere else
        [$seller, $managers] = $this->chain($company, 3);

        $this->sell($company, $seller);

        $this->assertSame(50_000, $this->overrideFor($managers[1]));
        $this->assertSame(10_000, $this->overrideFor($managers[2]));
        $this->assertSame(10_000, $this->overrideFor($managers[3]));
    }

    public function test_a_level_nobody_priced_and_no_catch_all_pays_nobody_but_does_not_stop_the_walk(): void
    {
        // A gap in the ladder is a gap, not a wall: levels above it that were
        // priced still get paid. Stopping would make one missing row silently
        // cancel every rate above it.
        $company = $this->company();
        $this->rate($company, 500, level: 1);
        $this->rate($company, 100, level: 3);
        [$seller, $managers] = $this->chain($company, 3);

        $this->sell($company, $seller);

        $this->assertSame(50_000, $this->overrideFor($managers[1]));
        $this->assertSame(0, $this->overrideFor($managers[2]));
        $this->assertSame(10_000, $this->overrideFor($managers[3]));
    }

    public function test_a_product_scoped_rate_still_beats_a_company_wide_levelled_one(): void
    {
        // TASK-214's contract, unchanged: scope is the outer ladder, level
        // sits inside each rung. Reversing it would mean the first
        // company-wide level rate quietly overrode every product rate.
        $company = $this->company();
        $this->rate($company, 900, level: 1); // company-wide, level 1, 9%

        $tier = $this->certify(User::factory()->agent()->create(['company_id' => $company->id]), $company);
        $product = Product::factory()->for($company)->create(['price_satang' => 1_000_000]);
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'level' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 200, // 2% for THIS product, every level
        ]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->certify($manager, $company);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        $sellerTier = $this->certify($seller, $company);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $sellerTier->id, 'product_id' => $product->id,
        ]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id,
            'product_id' => $product->id, 'branch' => 'Silom', 'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered, 'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->closeSale($referral, $seller);
        unset($tier);

        $this->assertSame(20_000, $this->overrideFor($manager));
    }

    // ── The depth cap ───────────────────────────────────────────────────────

    public function test_the_walk_stops_at_the_companys_configured_depth(): void
    {
        $company = $this->company(['max_override_depth' => 2]);
        $this->rate($company, 100);
        [$seller, $managers] = $this->chain($company, 4);

        $this->sell($company, $seller);

        $this->assertSame(10_000, $this->overrideFor($managers[1]));
        $this->assertSame(10_000, $this->overrideFor($managers[2]));
        $this->assertSame(0, $this->overrideFor($managers[3]));
        $this->assertSame(0, $this->overrideFor($managers[4]));
    }

    public function test_no_configured_depth_means_as_far_as_the_chain_goes(): void
    {
        $company = $this->company(['max_override_depth' => null]);
        $this->rate($company, 100);
        [$seller, $managers] = $this->chain($company, 4);

        $this->sell($company, $seller);

        foreach ([1, 2, 3, 4] as $level) {
            $this->assertSame(10_000, $this->overrideFor($managers[$level]), "level {$level}");
        }
    }

    // ── The form ────────────────────────────────────────────────────────────

    public function test_a_levelled_rate_may_not_carry_its_own_funding_mode(): void
    {
        // One walk up one chain has one funding model. Letting level 2 deduct
        // from the seller while level 1 was paid by the company would make the
        // seller's own row depend on how deep their upline happened to go.
        $company = $this->company();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-override-rules', [
                'company_id' => $company->id,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 200,
                'level' => 2,
                'override_mode' => CommissionOverrideMode::DeductFromCommission->value,
                'effective_from' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('override_mode');
    }

    public function test_the_depth_cap_is_saved_and_cleared_through_the_settings_endpoint(): void
    {
        $company = $this->company();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-settings', ['company_id' => $company->id, 'max_override_depth' => 3])
            ->assertOk();
        $this->assertSame(3, $company->fresh()->max_override_depth);

        // Explicit null is "no cap", and must be distinguishable from absent.
        $this->actingAs($admin)
            ->putJson('/api/v1/commission-settings', ['company_id' => $company->id, 'max_override_depth' => null])
            ->assertOk();
        $this->assertNull($company->fresh()->max_override_depth);

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'action' => 'commission_max_override_depth.updated',
        ]);
    }

    public function test_the_settings_endpoint_reads_the_cap_back_so_the_screen_can_show_it(): void
    {
        // Without this the screen that SETS the cap cannot show what is
        // currently set: every visit renders an empty field over a live
        // value, and saving looks like it did nothing.
        $company = $this->company(['max_override_depth' => 3]);
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->getJson("/api/v1/commission-settings?company_id={$company->id}")
            ->assertOk()
            ->assertJsonPath('data.max_override_depth', 3)
            ->assertJsonPath('data.override_compression', false);

        // NULL survives to the client — it is the one value on this endpoint
        // whose null is a real answer ("as far as the chain goes"), and a
        // coalesced number would be a cap nobody set.
        $company->update(['max_override_depth' => null]);

        $this->actingAs($admin)
            ->getJson("/api/v1/commission-settings?company_id={$company->id}")
            ->assertOk()
            ->assertJsonPath('data.max_override_depth', null);
    }
}
