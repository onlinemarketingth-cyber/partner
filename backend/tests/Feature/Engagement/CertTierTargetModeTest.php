<?php

namespace Tests\Feature\Engagement;

use App\Enums\CertTierTargetMode;
use App\Enums\CommissionRateType;
use App\Enums\PromotionPayoutTiming;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Models\AgentPromotion;
use App\Models\Announcement;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-042 §4 (Cert-tier "and above" targeting, BR-7 confirmed
// 2026-07-23): target_cert_tier_mode (exact/and_above) on both
// agent_promotions and announcements, compared against cert_tiers.sort_order.
//
// IMPORTANT (flagged by the prior implementer): CertTierFactory defaults
// sort_order to 0 for every factory-made tier — every CertTier below is
// therefore created directly via CertTier::create() with an explicit,
// distinct sort_order (Basic=1 < Intermediate=2 < High=3), never via the
// factory, or the and_above comparisons would be meaningless/flaky.
class CertTierTargetModeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: CertTier, 1: CertTier, 2: CertTier} [basic, intermediate, high] */
    private function makeTiers(): array
    {
        $basic = CertTier::create(['key' => 'basic', 'name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $intermediate = CertTier::create(['key' => 'intermediate', 'name' => 'Intermediate', 'sort_order' => 2, 'is_mandatory' => false]);
        $high = CertTier::create(['key' => 'high', 'name' => 'High', 'sort_order' => 3, 'is_mandatory' => false]);

        return [$basic, $intermediate, $high];
    }

    private function passTier(User $agent, Company $company, CertTier $tier): void
    {
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
    }

    private function makePromotion(Company $company, CertTier $targetTier, CertTierTargetMode $mode): AgentPromotion
    {
        return AgentPromotion::create([
            'company_id' => $company->id,
            'product_id' => null,
            'name' => 'Cert-tier targeted promotion',
            'description' => null,
            'target_type' => PromotionTargetType::CertTier,
            'target_cert_tier_id' => $targetTier->id,
            'target_cert_tier_mode' => $mode,
            'bonus_type' => CommissionRateType::FixedSatang,
            'bonus_value' => 10000,
            'payout_timing' => PromotionPayoutTiming::Immediate,
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'created_by' => null,
        ]);
    }

    // --- AgentPromotion::appliesToAgent() -----------------------------

    public function test_exact_mode_agent_holding_a_higher_tier_than_the_target_does_not_match(): void
    {
        [, $intermediate, $high] = $this->makeTiers();
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passTier($agent, $company, $high); // holds High, never explicitly passed Intermediate

        $promotion = $this->makePromotion($company, $intermediate, CertTierTargetMode::Exact);

        $this->assertFalse($promotion->appliesToAgent($agent));
    }

    public function test_and_above_mode_agent_holding_a_higher_tier_than_the_target_does_match(): void
    {
        [, $intermediate, $high] = $this->makeTiers();
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passTier($agent, $company, $high);

        $promotion = $this->makePromotion($company, $intermediate, CertTierTargetMode::AndAbove);

        $this->assertTrue($promotion->appliesToAgent($agent));
    }

    public function test_and_above_mode_agent_holding_a_lower_tier_than_the_target_does_not_match(): void
    {
        [$basic, $intermediate] = $this->makeTiers();
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passTier($agent, $company, $basic);

        $promotion = $this->makePromotion($company, $intermediate, CertTierTargetMode::AndAbove);

        $this->assertFalse($promotion->appliesToAgent($agent));
    }

    public function test_and_above_mode_agent_holding_the_exact_target_tier_does_match(): void
    {
        [, $intermediate] = $this->makeTiers();
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passTier($agent, $company, $intermediate);

        $promotion = $this->makePromotion($company, $intermediate, CertTierTargetMode::AndAbove);

        $this->assertTrue($promotion->appliesToAgent($agent));
    }

    // --- Announcement visibility (AnnouncementController::index) -----

    private function makeAnnouncement(Company $company, CertTier $targetTier, CertTierTargetMode $mode): Announcement
    {
        return Announcement::create([
            'company_id' => $company->id,
            'title' => 'Cert-tier targeted announcement',
            'content' => 'Congratulations on your progress!',
            'audience' => 'cert_tier',
            'target_cert_tier_id' => $targetTier->id,
            'target_cert_tier_mode' => $mode,
            'is_pinned' => false,
            'published_at' => now()->subMinute(),
            'expires_at' => null,
            'created_by' => null,
        ]);
    }

    public function test_exact_mode_announcement_not_visible_to_agent_holding_a_higher_tier(): void
    {
        [, $intermediate, $high] = $this->makeTiers();
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passTier($agent, $company, $high);
        $announcement = $this->makeAnnouncement($company, $intermediate, CertTierTargetMode::Exact);

        $this->actingAs($agent)->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonMissing(['id' => $announcement->id]);
    }

    public function test_and_above_mode_announcement_visible_to_agent_holding_a_higher_tier(): void
    {
        [, $intermediate, $high] = $this->makeTiers();
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passTier($agent, $company, $high);
        $announcement = $this->makeAnnouncement($company, $intermediate, CertTierTargetMode::AndAbove);

        $response = $this->actingAs($agent)->getJson('/api/v1/announcements')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($announcement->id, $ids);
    }

    public function test_and_above_mode_announcement_not_visible_to_agent_holding_a_lower_tier(): void
    {
        [$basic, $intermediate] = $this->makeTiers();
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passTier($agent, $company, $basic);
        $announcement = $this->makeAnnouncement($company, $intermediate, CertTierTargetMode::AndAbove);

        $this->actingAs($agent)->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonMissing(['id' => $announcement->id]);
    }

    public function test_and_above_mode_announcement_visible_to_agent_holding_the_exact_target_tier(): void
    {
        [, $intermediate] = $this->makeTiers();
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passTier($agent, $company, $intermediate);
        $announcement = $this->makeAnnouncement($company, $intermediate, CertTierTargetMode::AndAbove);

        $response = $this->actingAs($agent)->getJson('/api/v1/announcements')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($announcement->id, $ids);
    }
}
