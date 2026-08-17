<?php

namespace Tests\Feature\Engagement;

use App\Enums\GamificationSourceType;
use App\Enums\RewardType;
use App\Models\Company;
use App\Models\RewardItem;
use App\Models\RewardPointLedger;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-042 §2 (Physical reward fulfillment, BR-7 confirmed 2026-07-23):
// shipping details are captured on the redemption request form itself
// (StoreRewardRedemptionRequest), required only when the target
// RewardItem.reward_type is physical; digital items never persist them
// even if sent (RewardRedemptionService::requestRedemption()). tracking_number
// is a plain Admin-editable field, settable only once a redemption reaches
// Approved (RewardRedemptionService::updateTrackingNumber()). Company Admin
// authorization + TenantScope must hold for both the /decide-adjacent
// tracking-number route and plain show().
class RewardRedemptionFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private function givePoints(User $agent, Company $company, int $points): void
    {
        RewardPointLedger::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'source_type' => GamificationSourceType::PipelineStageAdvanced,
            'source_id' => 1,
            'points_awarded' => $points,
            'xp_ledger_id' => null,
        ]);
    }

    private function makeItem(Company $company, RewardType $type, int $cost = 10): RewardItem
    {
        return RewardItem::create([
            'company_id' => $company->id,
            'name' => 'Test Reward',
            'description' => null,
            'cost_points' => $cost,
            'stock_quantity' => null,
            'is_active' => true,
            'reward_type' => $type,
        ]);
    }

    public function test_redeeming_a_physical_item_without_shipping_fields_fails_422(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->givePoints($agent, $company, 100);
        $item = $this->makeItem($company, RewardType::Physical);

        $this->actingAs($agent)
            ->postJson('/api/v1/reward-redemptions', ['reward_item_id' => $item->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_recipient_name', 'shipping_phone', 'shipping_address']);

        $this->assertDatabaseCount('reward_redemptions', 0);
    }

    public function test_redeeming_a_physical_item_with_shipping_fields_succeeds_and_persists_them(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->givePoints($agent, $company, 100);
        $item = $this->makeItem($company, RewardType::Physical);

        $payload = [
            'reward_item_id' => $item->id,
            'shipping_recipient_name' => 'Somchai Jaidee',
            'shipping_phone' => '0812345678',
            'shipping_address' => '123 Sukhumvit Rd, Bangkok',
        ];

        $this->actingAs($agent)
            ->postJson('/api/v1/reward-redemptions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.shipping_recipient_name', 'Somchai Jaidee')
            ->assertJsonPath('data.shipping_phone', '0812345678')
            ->assertJsonPath('data.shipping_address', '123 Sukhumvit Rd, Bangkok');

        $this->assertDatabaseHas('reward_redemptions', [
            'reward_item_id' => $item->id,
            'shipping_recipient_name' => 'Somchai Jaidee',
            'shipping_phone' => '0812345678',
            'shipping_address' => '123 Sukhumvit Rd, Bangkok',
        ]);
    }

    public function test_redeeming_a_digital_item_without_shipping_fields_succeeds(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->givePoints($agent, $company, 100);
        $item = $this->makeItem($company, RewardType::Digital);

        $this->actingAs($agent)
            ->postJson('/api/v1/reward-redemptions', ['reward_item_id' => $item->id])
            ->assertCreated();

        $this->assertDatabaseCount('reward_redemptions', 1);
    }

    public function test_shipping_data_sent_for_a_digital_item_is_never_persisted(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->givePoints($agent, $company, 100);
        $item = $this->makeItem($company, RewardType::Digital);

        // Defense in depth: even though shipping_* is optional for a
        // digital item, a client sending it anyway must never see it
        // stored — reward_type on the RewardItem is the single source
        // of truth (RewardRedemptionService::requestRedemption()).
        $response = $this->actingAs($agent)
            ->postJson('/api/v1/reward-redemptions', [
                'reward_item_id' => $item->id,
                'shipping_recipient_name' => 'Should Not Persist',
                'shipping_phone' => '0899999999',
                'shipping_address' => 'Should not persist either',
            ])
            ->assertCreated();

        $redemptionId = $response->json('data.id');

        $this->assertDatabaseHas('reward_redemptions', [
            'id' => $redemptionId,
            'shipping_recipient_name' => null,
            'shipping_phone' => null,
            'shipping_address' => null,
        ]);
    }

    public function test_update_tracking_number_fails_while_pending_and_succeeds_once_approved(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->givePoints($agent, $company, 100);
        $item = $this->makeItem($company, RewardType::Physical);

        $redemption = RewardRedemption::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'reward_item_id' => $item->id,
            'points_spent' => $item->cost_points,
            'status' => 'pending',
            'requested_at' => now(),
            'shipping_recipient_name' => 'Somchai Jaidee',
            'shipping_phone' => '0812345678',
            'shipping_address' => '123 Sukhumvit Rd',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/reward-redemptions/{$redemption->id}/tracking-number", ['tracking_number' => 'TH1234567890'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->postJson("/api/v1/reward-redemptions/{$redemption->id}/decide", ['status' => 'approved'])
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson("/api/v1/reward-redemptions/{$redemption->id}/tracking-number", ['tracking_number' => 'TH1234567890'])
            ->assertOk()
            ->assertJsonPath('data.tracking_number', 'TH1234567890');

        $this->assertDatabaseHas('reward_redemptions', [
            'id' => $redemption->id,
            'tracking_number' => 'TH1234567890',
        ]);
    }

    public function test_company_admin_from_another_company_cannot_view_or_update_tracking_number(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $this->givePoints($agentA, $companyA, 100);
        $item = $this->makeItem($companyA, RewardType::Physical);

        $redemption = RewardRedemption::create([
            'company_id' => $companyA->id,
            'user_id' => $agentA->id,
            'reward_item_id' => $item->id,
            'points_spent' => $item->cost_points,
            'status' => 'approved',
            'requested_at' => now(),
            'decided_at' => now(),
            'shipping_recipient_name' => 'Somchai Jaidee',
            'shipping_phone' => '0812345678',
            'shipping_address' => '123 Sukhumvit Rd',
        ]);

        $this->actingAs($adminB)
            ->getJson("/api/v1/reward-redemptions/{$redemption->id}")
            ->assertStatus(404);

        $this->actingAs($adminB)
            ->patchJson("/api/v1/reward-redemptions/{$redemption->id}/tracking-number", ['tracking_number' => 'HACKED'])
            ->assertStatus(404);

        $this->assertDatabaseHas('reward_redemptions', [
            'id' => $redemption->id,
            'tracking_number' => null,
        ]);
    }
}
