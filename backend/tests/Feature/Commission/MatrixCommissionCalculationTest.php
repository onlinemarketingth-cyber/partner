<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionMatrixLevelRate;
use App\Models\CommissionMatrixSetting;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\MatrixPlacement;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-030 — Matrix MLM plan type: forced width x depth
// placement (via PUT /users/{user} setting manager_id — see
// UserService::update()'s own comment for why placement piggybacks on
// TASK-025's existing endpoint) and per-level override payout (fires
// inside CommissionService::recordForReferral(), same call site as
// Unilevel/Binary).
class MatrixCommissionCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);

        return $tier;
    }

    private function advanceToStage(Referral $referral, User $agent, PipelineStage $target): Referral
    {
        while ($referral->current_stage !== $target) {
            $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
            $referral->refresh();
        }

        return $referral;
    }

    // --- Placement ---

    public function test_placing_the_first_agent_creates_the_sponsor_as_root(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Matrix->value]);
        CommissionMatrixSetting::factory()->create(['company_id' => $company->id, 'width' => 3, 'depth' => 5]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $sponsor = User::factory()->agent()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$agent->id}", ['manager_id' => $sponsor->id])->assertOk();

        $this->assertDatabaseHas('matrix_placements', ['user_id' => $sponsor->id, 'parent_id' => null, 'position' => 0]);
        $this->assertDatabaseHas('matrix_placements', ['user_id' => $agent->id, 'parent_id' => $sponsor->id, 'position' => 0]);
    }

    public function test_width_limit_triggers_breadth_first_spillover(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Matrix->value]);
        CommissionMatrixSetting::factory()->create(['company_id' => $company->id, 'width' => 2, 'depth' => 5]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $sponsor = User::factory()->agent()->create(['company_id' => $company->id]);

        $child1 = User::factory()->agent()->create(['company_id' => $company->id]);
        $child2 = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->putJson("/api/v1/users/{$child1->id}", ['manager_id' => $sponsor->id])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/users/{$child2->id}", ['manager_id' => $sponsor->id])->assertOk();

        // Sponsor's 2 slots (width=2) are now both full — a 3rd recruit
        // under the SAME sponsor must spill to the first child with an
        // open slot (breadth-first), not stack onto the sponsor.
        $spillover = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->putJson("/api/v1/users/{$spillover->id}", ['manager_id' => $sponsor->id])->assertOk();

        $spilloverPlacement = MatrixPlacement::where('user_id', $spillover->id)->first();
        $this->assertContains($spilloverPlacement->parent_id, [$child1->id, $child2->id]);
        $this->assertNotEquals($sponsor->id, $spilloverPlacement->parent_id);
    }

    // TASK-034 QA gap-fill — the existing spillover test above only ever
    // needs ONE hop (both of the sponsor's own slots full, spill to a
    // direct child). findOpenSlotBreadthFirst() is a real BFS that walks
    // the whole tree level by level (see its own docblock — "no depth
    // limit on the SEARCH itself"), but nothing previously proved the
    // queue-based walk actually continues past the sponsor's immediate
    // children when THEY are also full. width=1 here forces every node
    // to saturate after exactly one child, so the 3rd recruit must
    // travel 2 hops (sponsor -> child1 -> child2) to find an opening.
    public function test_spillover_travels_two_hops_when_the_first_level_is_also_full(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Matrix->value]);
        CommissionMatrixSetting::factory()->create(['company_id' => $company->id, 'width' => 1, 'depth' => 5]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $sponsor = User::factory()->agent()->create(['company_id' => $company->id]);

        $child1 = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->putJson("/api/v1/users/{$child1->id}", ['manager_id' => $sponsor->id])->assertOk();
        // Sponsor (width=1) is now full; this one spills exactly 1 hop, under child1.
        $child2 = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->putJson("/api/v1/users/{$child2->id}", ['manager_id' => $sponsor->id])->assertOk();
        $this->assertDatabaseHas('matrix_placements', ['user_id' => $child2->id, 'parent_id' => $child1->id]);

        // Sponsor AND child1 are now both full (width=1 each) — this one
        // must travel a 2nd hop, landing under child2.
        $child3 = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->putJson("/api/v1/users/{$child3->id}", ['manager_id' => $sponsor->id])->assertOk();

        $this->assertDatabaseHas('matrix_placements', ['user_id' => $child3->id, 'parent_id' => $child2->id]);
    }

    public function test_placing_without_matrix_settings_configured_is_rejected(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Matrix->value]);
        // Deliberately no CommissionMatrixSetting row.
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $sponsor = User::factory()->agent()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$agent->id}", ['manager_id' => $sponsor->id])
            ->assertUnprocessable();
    }

    public function test_reassigning_manager_does_not_move_an_already_placed_agent(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Matrix->value]);
        CommissionMatrixSetting::factory()->create(['company_id' => $company->id, 'width' => 3, 'depth' => 5]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $sponsorA = User::factory()->agent()->create(['company_id' => $company->id]);
        $sponsorB = User::factory()->agent()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$agent->id}", ['manager_id' => $sponsorA->id])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/users/{$agent->id}", ['manager_id' => $sponsorB->id])->assertOk();

        // manager_id itself DID change (Unilevel/Binary's chain still
        // updates), but the Matrix placement is permanent — still under
        // sponsorA's tree, not moved to sponsorB.
        $this->assertSame($sponsorB->id, $agent->fresh()->manager_id);
        $this->assertDatabaseHas('matrix_placements', ['user_id' => $agent->id, 'parent_id' => $sponsorA->id]);
        $this->assertDatabaseMissing('matrix_placements', ['user_id' => $agent->id, 'parent_id' => $sponsorB->id]);
    }

    // --- Commission payout ---

    public function test_direct_sale_pays_level_rates_up_to_the_configured_depth(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Matrix->value]);
        CommissionMatrixSetting::factory()->create(['company_id' => $company->id, 'width' => 3, 'depth' => 2]);
        CommissionMatrixLevelRate::factory()->create(['company_id' => $company->id, 'level' => 1, 'rate_value' => 500]); // 5%
        CommissionMatrixLevelRate::factory()->create(['company_id' => $company->id, 'level' => 2, 'rate_value' => 300]); // 3%
        // Deliberately no level-3 rate — depth=2 should stop the walk
        // before it would ever matter anyway.

        $level3 = User::factory()->agent()->create(['company_id' => $company->id]);
        $level2 = User::factory()->agent()->create(['company_id' => $company->id]);
        $level1 = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id]);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        // Build the matrix tree in sponsor order (root first, via the
        // PUT endpoint so MatrixCommissionService::place() actually
        // fires) so each placement's sponsor is already in the tree.
        $this->actingAs($admin)->putJson("/api/v1/users/{$level2->id}", ['manager_id' => $level3->id])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/users/{$level1->id}", ['manager_id' => $level2->id])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/users/{$seller->id}", ['manager_id' => $level1->id])->assertOk();

        $tier = $this->passBasicCert($seller, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $seller, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $level1->id, 'earned_via' => CommissionEarnedVia::MatrixOverride->value, 'amount_satang' => 50000,
        ]);
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $level2->id, 'earned_via' => CommissionEarnedVia::MatrixOverride->value, 'amount_satang' => 30000,
        ]);
        // level3 is 3 hops up — beyond depth=2 — must receive nothing.
        $this->assertDatabaseMissing('commission_ledger', ['agent_id' => $level3->id, 'earned_via' => CommissionEarnedVia::MatrixOverride->value]);
        $this->assertSame(2, CommissionLedger::where('earned_via', CommissionEarnedVia::MatrixOverride->value)->count());
    }

    public function test_a_level_with_no_configured_rate_receives_no_ledger_row(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Matrix->value]);
        CommissionMatrixSetting::factory()->create(['company_id' => $company->id, 'width' => 3, 'depth' => 3]);
        // Only level 1 has a configured rate — level 2 deliberately doesn't.
        CommissionMatrixLevelRate::factory()->create(['company_id' => $company->id, 'level' => 1, 'rate_value' => 500]);

        $sponsor = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->putJson("/api/v1/users/{$seller->id}", ['manager_id' => $sponsor->id])->assertOk();

        $tier = $this->passBasicCert($seller, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 500000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $seller, PipelineStage::CompletePayment);

        $this->assertSame(1, CommissionLedger::where('earned_via', CommissionEarnedVia::MatrixOverride->value)->count());
        $this->assertDatabaseHas('commission_ledger', ['agent_id' => $sponsor->id, 'earned_via' => CommissionEarnedVia::MatrixOverride->value]);
    }

    public function test_matrix_plan_type_does_not_also_fire_unilevel_overrides(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Matrix->value]);
        CommissionMatrixSetting::factory()->create(['company_id' => $company->id, 'width' => 3, 'depth' => 5]);
        $managerTier = CertTier::factory()->create();
        $sponsor = User::factory()->agent()->create(['company_id' => $company->id]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $sponsor->id, 'cert_tier_id' => $managerTier->id, 'passed_at' => now()]);
        CommissionOverrideRule::factory()->create(['company_id' => $company->id, 'manager_cert_tier_id' => $managerTier->id, 'rate_value' => 900]);

        $seller = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->actingAs($admin)->putJson("/api/v1/users/{$seller->id}", ['manager_id' => $sponsor->id])->assertOk();

        $tier = $this->passBasicCert($seller, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 500000]);
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $seller, PipelineStage::CompletePayment);

        $this->assertSame(0, CommissionLedger::where('earned_via', CommissionEarnedVia::Override->value)->count());
    }
}
