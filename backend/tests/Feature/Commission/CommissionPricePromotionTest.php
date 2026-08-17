<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Enums\PromotionStatus;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductPricePromotion;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-047 — human-confirmed: commission must be computed against a
// product's DISCOUNTED price when a ProductPricePromotion is active at
// the moment CommissionService fires (Complete Payment, BR-4's trigger
// point), and the row that fires must stamp an immutable snapshot of
// which price/promotion was actually used
// (sale_price_satang_at_time / applied_price_promotion_id_at_time).
class CommissionPricePromotionTest extends TestCase
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

    private function makeReferral(Company $company, User $agent, Client $client, Product $product): Referral
    {
        return Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
    }

    public function test_an_active_promotion_discounts_the_commission_amount_and_stamps_the_snapshot_columns(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]); // 8,900 THB normal price
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300, // 3.00%
        ]);
        $promotion = ProductPricePromotion::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'discounted_price_satang' => 750000, // 7,500 THB — discounted
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $referral = $this->makeReferral($company, $agent, $client, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 22500, // 750000 * 300 / 10000 — off the DISCOUNTED price, not 890000
            'sale_price_satang_at_time' => 750000,
            'applied_price_promotion_id_at_time' => $promotion->id,
        ]);
    }

    public function test_no_active_promotion_uses_the_normal_price_and_leaves_snapshot_columns_recording_the_normal_price_with_a_null_promotion(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);
        // Deliberately no ProductPricePromotion at all for this product.

        $referral = $this->makeReferral($company, $agent, $client, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 26700, // 890000 * 300 / 10000 — normal price
            'sale_price_satang_at_time' => 890000,
            'applied_price_promotion_id_at_time' => null,
        ]);
    }

    public function test_a_draft_status_promotion_is_ignored(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);
        ProductPricePromotion::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'discounted_price_satang' => 750000,
            'status' => PromotionStatus::Draft, // not yet published — must not apply
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $referral = $this->makeReferral($company, $agent, $client, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 26700,
            'sale_price_satang_at_time' => 890000,
            'applied_price_promotion_id_at_time' => null,
        ]);
    }

    public function test_an_ended_status_promotion_is_ignored(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);
        ProductPricePromotion::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'discounted_price_satang' => 750000,
            'status' => PromotionStatus::Ended,
            'starts_at' => now()->subDays(10)->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
        ]);

        $referral = $this->makeReferral($company, $agent, $client, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 26700,
            'applied_price_promotion_id_at_time' => null,
        ]);
    }

    public function test_a_promotion_whose_start_date_is_in_the_future_is_ignored(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);
        ProductPricePromotion::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'discounted_price_satang' => 750000,
            'status' => PromotionStatus::Active, // status is Active, but...
            'starts_at' => now()->addDay()->toDateString(), // ...the date window hasn't opened yet
            'ends_at' => null,
        ]);

        $referral = $this->makeReferral($company, $agent, $client, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 26700,
            'applied_price_promotion_id_at_time' => null,
        ]);
    }

    public function test_an_open_ended_active_promotion_with_no_ends_at_still_applies(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);
        $promotion = ProductPricePromotion::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'discounted_price_satang' => 750000,
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => null, // open-ended
        ]);

        $referral = $this->makeReferral($company, $agent, $client, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 22500,
            'applied_price_promotion_id_at_time' => $promotion->id,
        ]);
    }

    public function test_when_two_active_promotions_overlap_the_newest_by_id_wins(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);
        // Pre-existing schema gap (no unique constraint stops two active
        // promotions overlapping on the same product) — CommissionService
        // deliberately breaks the tie deterministically via latest('id').
        ProductPricePromotion::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'discounted_price_satang' => 800000, 'status' => PromotionStatus::Active,
            'starts_at' => now()->subDays(5)->toDateString(), 'ends_at' => now()->addDay()->toDateString(),
        ]);
        $newerPromotion = ProductPricePromotion::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'discounted_price_satang' => 750000, 'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay()->toDateString(), 'ends_at' => now()->addDay()->toDateString(),
        ]);

        $referral = $this->makeReferral($company, $agent, $client, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 22500, // off the newer promotion's 750000, not the older one's 800000
            'applied_price_promotion_id_at_time' => $newerPromotion->id,
        ]);
    }

    public function test_a_promotion_active_for_a_different_product_does_not_apply(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        $otherProduct = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 500000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300,
        ]);
        ProductPricePromotion::create([
            'company_id' => $company->id, 'product_id' => $otherProduct->id, // different product
            'discounted_price_satang' => 100000, 'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay()->toDateString(), 'ends_at' => now()->addDay()->toDateString(),
        ]);

        $referral = $this->makeReferral($company, $agent, $client, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'amount_satang' => 26700,
            'applied_price_promotion_id_at_time' => null,
        ]);
    }

    // --- Unilevel override rows must also receive the snapshot columns ---

    public function test_a_unilevel_override_row_also_receives_the_promotion_aware_amount_and_snapshot_columns(): void
    {
        $company = Company::factory()->create();
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $managerTier = CertTier::factory()->create(['key' => 'unit_manager_tier', 'sort_order' => 2]);

        $manager = User::factory()->agent()->create(['company_id' => $company->id]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $manager->id, 'cert_tier_id' => $managerTier->id, 'passed_at' => now()]);

        $agent = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $manager->id]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $basic->id, 'passed_at' => now()]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]); // 10,000 THB
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300, // 3%
        ]);
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id, 'manager_cert_tier_id' => $managerTier->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 100, // 1% override
        ]);
        $promotion = ProductPricePromotion::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'discounted_price_satang' => 800000, // 8,000 THB discounted
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        $referral = $this->makeReferral($company, $agent, $client, $product);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $agent->id,
            'earned_via' => CommissionEarnedVia::Direct->value,
            'amount_satang' => 24000, // 800000 * 300 / 10000
            'sale_price_satang_at_time' => 800000,
            'applied_price_promotion_id_at_time' => $promotion->id,
        ]);
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $manager->id,
            'earned_via' => CommissionEarnedVia::Override->value,
            'amount_satang' => 8000, // 800000 * 100 / 10000 — override row is ALSO promotion-aware
            'sale_price_satang_at_time' => 800000,
            'applied_price_promotion_id_at_time' => $promotion->id,
        ]);
    }
}
