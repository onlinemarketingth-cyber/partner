<?php

namespace Tests\Feature\Commission;

use App\Enums\BinaryLeg;
use App\Enums\CommissionPlanType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Somebody can finally be put on a leg.
 *
 * ═══ WHAT WAS ACTUALLY BROKEN ═══
 *
 * `users.binary_leg` shipped with ADR-006 Round 4, along with the enum, the
 * volume table, the matching cycles and the whole payout engine. Every one of
 * those was built and tested. What was never built was any way to SET the
 * column: no Form Request accepted it, no controller wrote it, no screen
 * offered it. Outside a test factory it could only ever be null.
 *
 * A Binary company therefore worked exactly as designed and paid nothing at
 * all, for ever: BinaryCommissionService::creditVolume() has a leg to credit
 * or it returns, and it always returned. Nothing reported it, because from
 * the code's point of view nothing had gone wrong.
 *
 * The tests below are in this file rather than appended to
 * BinaryCommissionCalculationTest on purpose: that suite proves the engine
 * computes correctly GIVEN a placement, and it did so by writing the column
 * straight through a factory — which is exactly how a missing write path
 * stays invisible for months. This one proves the placement can be made
 * through the product.
 */
class BinaryLegPlacementTest extends TestCase
{
    use RefreshDatabase;

    private function binaryCompany(): Company
    {
        return Company::factory()->create(['commission_plan_type' => CommissionPlanType::Binary->value]);
    }

    private function admin(Company $company): User
    {
        return User::factory()->companyAdmin()->create(['company_id' => $company->id]);
    }

    public function test_an_admin_can_place_an_agent_on_a_leg(): void
    {
        $company = $this->binaryCompany();
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'binary_leg' => null]);

        $this->actingAs($this->admin($company))
            ->putJson("/api/v1/users/{$agent->id}", ['binary_leg' => BinaryLeg::Right->value])
            ->assertOk();

        $this->assertSame(BinaryLeg::Right, $agent->fresh()->binary_leg);
    }

    public function test_a_placement_can_be_moved_and_cleared(): void
    {
        $company = $this->binaryCompany();
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id, 'binary_leg' => BinaryLeg::Left->value,
        ]);
        $admin = $this->admin($company);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['binary_leg' => BinaryLeg::Right->value])
            ->assertOk();
        $this->assertSame(BinaryLeg::Right, $agent->fresh()->binary_leg);

        // Clearing is a real state, not a no-op: it is where a recruit sits
        // before the referrer has decided which side to build.
        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['binary_leg' => null])
            ->assertOk();
        $this->assertNull($agent->fresh()->binary_leg);
    }

    public function test_the_placement_is_written_to_the_audit_trail(): void
    {
        // On a Binary company the leg decides which volume column an entire
        // sub-tree's sales roll into — §6 audits actions that affect money.
        $company = $this->binaryCompany();
        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'binary_leg' => null]);

        $this->actingAs($this->admin($company))
            ->putJson("/api/v1/users/{$agent->id}", ['binary_leg' => BinaryLeg::Left->value])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'action' => 'user.binary_leg_changed',
            'auditable_id' => $agent->id,
        ]);
    }

    public function test_a_company_that_does_not_run_binary_is_refused_rather_than_ignored(): void
    {
        // Same reasoning as the per-product plan type: a field that saves and
        // then governs nothing is how somebody comes to believe a setting is
        // in force when it never was.
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($this->admin($company))
            ->putJson("/api/v1/users/{$agent->id}", ['binary_leg' => BinaryLeg::Left->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('binary_leg');

        $this->assertNull($agent->fresh()->binary_leg);
    }

    public function test_an_invalid_leg_is_refused(): void
    {
        $company = $this->binaryCompany();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($this->admin($company))
            ->putJson("/api/v1/users/{$agent->id}", ['binary_leg' => 'middle'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('binary_leg');
    }

    public function test_an_edit_that_does_not_mention_the_leg_leaves_it_alone(): void
    {
        $company = $this->binaryCompany();
        $agent = User::factory()->agent()->create([
            'company_id' => $company->id, 'binary_leg' => BinaryLeg::Left->value,
        ]);

        $this->actingAs($this->admin($company))
            ->putJson("/api/v1/users/{$agent->id}", ['name' => 'ชื่อใหม่'])
            ->assertOk();

        $this->assertSame(BinaryLeg::Left, $agent->fresh()->binary_leg);
    }

    public function test_an_agent_placed_through_the_endpoint_credits_volume_on_a_real_sale(): void
    {
        // The whole point, end to end: place through the product, then sell,
        // and see the money arrive on the side the admin chose. Before today
        // this could only be reached by writing the column from a factory.
        $company = $this->binaryCompany();
        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create([
            'company_id' => $company->id, 'manager_id' => $manager->id, 'binary_leg' => null,
        ]);

        $this->actingAs($this->admin($company))
            ->putJson("/api/v1/users/{$seller->id}", ['binary_leg' => BinaryLeg::Right->value])
            ->assertOk();

        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id, 'user_id' => $seller->id, 'cert_tier_id' => $tier->id, 'passed_at' => now(),
        ]);
        $product = Product::factory()->for($company)->create(['price_satang' => 500_000]);
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

        $this->assertDatabaseHas('binary_leg_volumes', [
            'company_id' => $company->id,
            'agent_id' => $manager->id,
            'left_volume_satang' => 0,
            'right_volume_satang' => 500_000,
        ]);
    }
}
