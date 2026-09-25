<?php

namespace Tests\Feature\Catalog;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionPlanType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One plan per company — the product no longer gets a vote.
 *
 * Owner, 2026-09-19: "ปิดช่องตั้งแผนต่อสินค้า ... ถ้าเจตนาคือบริษัทเดียว
 * แผนเดียว", confirming what ADR-006 Round 3/4 had already recorded on
 * 2026-07-14 and TASK-027 then widened inside Product::effectivePlanType().
 *
 * Two halves, and both are needed. The RESOLUTION half stops a stored value
 * changing how a sale is calculated — that is the money. The FORM half stops
 * a new one being stored at all — because a field that saves and then gets
 * ignored is how somebody comes to believe a product pays differently, and
 * finds out otherwise from a payout they cannot take back (BR-4).
 */
class ProductPlanTypeFollowsTheCompanyTest extends TestCase
{
    use RefreshDatabase;

    private function sell(Company $company, User $seller, Product $product): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::firstOrCreate(
            ['user_id' => $seller->id, 'cert_tier_id' => $tier->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );
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

    // ── Resolution: what a stored value can still do (nothing) ──────────────

    public function test_a_sale_uses_the_company_plan_even_when_the_product_stores_another(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id, 'product_id' => null, 'product_category_id' => null,
            'manager_cert_tier_id' => null, 'rate_value' => 100, // 1%
        ]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        UserCertification::firstOrCreate(
            ['user_id' => $manager->id, 'cert_tier_id' => CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true])->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );

        // Stored straight on the row, the way it could be until today.
        $product = Product::factory()->for($company)->create([
            'price_satang' => 1_000_000,
            'commission_plan_type' => CommissionPlanType::Binary->value,
        ]);

        $this->sell($company, $seller, $product);

        // The company's Unilevel override fired…
        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value,
            'amount_satang' => 10_000,
        ]);
        // …and the Binary engine never ran, so no leg volume exists at all.
        $this->assertDatabaseCount('binary_leg_volumes', 0);
    }

    public function test_a_platform_product_follows_the_company_that_sells_it(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id, 'product_id' => null, 'product_category_id' => null,
            'manager_cert_tier_id' => null, 'rate_value' => 100,
        ]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        UserCertification::firstOrCreate(
            ['user_id' => $manager->id, 'cert_tier_id' => CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true])->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );

        // A shared row carrying Binary as its own plan type (ADR-040 requires
        // it to carry one). The selling company runs Unilevel, and the agents
        // of that company were promised Unilevel.
        $shared = Product::factory()->create([
            'company_id' => null,
            'price_satang' => 1_000_000,
            'commission_plan_type' => CommissionPlanType::Binary->value,
        ]);
        CompanyProductSetting::create([
            'company_id' => $company->id, 'product_id' => $shared->id,
            'price_satang' => 1_000_000, 'is_active' => true,
        ]);

        $this->sell($company, $seller, $shared);

        $this->assertDatabaseHas('commission_ledger', [
            'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value,
            'amount_satang' => 10_000,
        ]);
        $this->assertDatabaseCount('binary_leg_volumes', 0);
    }

    public function test_a_platform_product_asked_about_by_nobody_still_answers_from_its_own_column(): void
    {
        // The one rung the product keeps: no company is asking, so its own
        // value is the only honest answer. This is what ADR-040 required the
        // column for, and why it is not dropped.
        $shared = Product::factory()->create([
            'company_id' => null,
            'commission_plan_type' => CommissionPlanType::Matrix->value,
        ]);

        $this->assertSame(CommissionPlanType::Matrix, $shared->effectivePlanType());
    }

    // ── The form: what can still be stored ──────────────────────────────────

    public function test_setting_a_plan_type_on_a_company_product_is_refused(): void
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);
        $product = Product::factory()->for($company)->create(['commission_plan_type' => null]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$product->id}", ['commission_plan_type' => CommissionPlanType::Binary->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('commission_plan_type');

        $this->assertNull($product->fresh()->commission_plan_type);
    }

    public function test_clearing_a_stray_plan_type_back_to_null_is_still_allowed(): void
    {
        // The tidy-up path for a row that already carries one. Refusing this
        // too would leave the stray value permanently unremovable through the
        // product screen, which is the opposite of the point.
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);
        $product = Product::factory()->for($company)->create([
            'commission_plan_type' => CommissionPlanType::Binary->value,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$product->id}", ['commission_plan_type' => null])
            ->assertOk();

        $this->assertNull($product->fresh()->commission_plan_type);
    }

    public function test_a_platform_product_may_still_have_its_plan_type_changed(): void
    {
        $shared = Product::factory()->create([
            'company_id' => null,
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$shared->id}", ['commission_plan_type' => CommissionPlanType::Matrix->value])
            ->assertOk();

        $this->assertSame(CommissionPlanType::Matrix, $shared->fresh()->commission_plan_type);
    }

    public function test_an_edit_that_does_not_mention_the_plan_type_is_unaffected(): void
    {
        // `prohibited` rules are easy to over-apply into "you may never save
        // this product again". A name change on a product that happens to
        // carry a stray plan type must still go through.
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel->value]);
        $product = Product::factory()->for($company)->create([
            'commission_plan_type' => CommissionPlanType::Binary->value,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$product->id}", ['name' => 'ชื่อใหม่'])
            ->assertOk();

        $this->assertSame('ชื่อใหม่', $product->fresh()->name);
    }
}
