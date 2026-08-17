<?php

namespace Tests\Feature\Engagement;

use App\Enums\GamificationSourceType;
use App\Enums\RedemptionStatus;
use App\Enums\RewardType;
use App\Models\Company;
use App\Models\GamificationRule;
use App\Models\RewardItem;
use App\Models\RewardPointLedger;
use App\Models\User;
use App\Models\XpLedger;
use App\Services\Engagement\RewardRedemptionService;
use App\Services\Gamification\GamificationService;
use App\Services\Gamification\LevelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-042 §1 (BR-7 "Option B" confirmed 2026-07-23): Reward Points are a
// currency decoupled from XP (BR-5), backed by reward_point_ledger, which
// mirrors every XP award 1:1 (GamificationService::awardXp()). Redeeming a
// reward spends against reward_point_ledger only (RewardRedemptionService::
// calculateAvailablePoints()) and must never touch xp_ledger, so Level/
// Leaderboard (Phase 9, LevelService) can never be affected by redemption
// activity. Same seedDefaultRule()/passBasicCert()-less style as
// XpAwardingTest — this file exercises GamificationService directly since
// the mirror hook lives inside awardXp() itself, not behind any one HTTP
// trigger.
class RewardPointLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function seedDefaultRule(GamificationSourceType $sourceType, int $xpValue): GamificationRule
    {
        return GamificationRule::create([
            'company_id' => null,
            'source_type' => $sourceType,
            'xp_value' => $xpValue,
            'is_active' => true,
        ]);
    }

    public function test_awarding_xp_creates_a_matching_reward_point_ledger_row(): void
    {
        $this->seedDefaultRule(GamificationSourceType::ModuleCompleted, 10);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $xpLedger = app(GamificationService::class)->awardXp($agent, GamificationSourceType::ModuleCompleted, 555);

        $this->assertNotNull($xpLedger);
        $this->assertSame(10, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));
        $this->assertSame(10, (int) RewardPointLedger::where('user_id', $agent->id)->sum('points_awarded'));

        $pointRow = RewardPointLedger::where('user_id', $agent->id)->first();
        $this->assertSame($company->id, $pointRow->company_id);
        $this->assertSame(GamificationSourceType::ModuleCompleted, $pointRow->source_type);
        $this->assertSame(555, $pointRow->source_id);
        $this->assertSame($xpLedger->id, $pointRow->xp_ledger_id);
    }

    public function test_multiple_xp_awards_each_mirror_into_their_own_reward_point_ledger_row(): void
    {
        $this->seedDefaultRule(GamificationSourceType::ModuleCompleted, 10);
        $this->seedDefaultRule(GamificationSourceType::ReferralSubmitted, 20);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        app(GamificationService::class)->awardXp($agent, GamificationSourceType::ModuleCompleted, 1);
        app(GamificationService::class)->awardXp($agent, GamificationSourceType::ReferralSubmitted, 2);

        $this->assertSame(30, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));
        $this->assertSame(30, (int) RewardPointLedger::where('user_id', $agent->id)->sum('points_awarded'));
        $this->assertSame(2, RewardPointLedger::where('user_id', $agent->id)->count());
    }

    public function test_no_active_rule_awards_neither_xp_nor_reward_points(): void
    {
        // Deliberately no GamificationRule seeded — same non-blocking
        // philosophy as XpAwardingTest's equivalent case.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $result = app(GamificationService::class)->awardXp($agent, GamificationSourceType::ModuleCompleted, 1);

        $this->assertNull($result);
        $this->assertDatabaseCount('xp_ledger', 0);
        $this->assertDatabaseCount('reward_point_ledger', 0);
    }

    public function test_redeeming_a_reward_reduces_available_points_but_never_touches_xp_ledger_or_level(): void
    {
        $this->seedDefaultRule(GamificationSourceType::ModuleCompleted, 100);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        app(GamificationService::class)->awardXp($agent, GamificationSourceType::ModuleCompleted, 1);

        $xpBefore = XpLedger::where('user_id', $agent->id)->sum('xp_awarded');
        $rewardPointRowsBefore = RewardPointLedger::where('user_id', $agent->id)->count();
        $levelBefore = app(LevelService::class)->currentLevelForUser($agent);

        $item = RewardItem::create([
            'company_id' => $company->id,
            'name' => 'Company Mug',
            'description' => null,
            'cost_points' => 30,
            'stock_quantity' => null,
            'is_active' => true,
            'reward_type' => RewardType::Digital,
        ]);

        $service = app(RewardRedemptionService::class);
        $this->assertSame(100, $service->calculateAvailablePoints($agent));

        $redemption = $service->requestRedemption($item, $agent);
        $this->assertSame(70, $service->calculateAvailablePoints($agent));

        $service->decide($redemption, RedemptionStatus::Approved, $admin);
        $service->decide($redemption->fresh(), RedemptionStatus::Fulfilled, $admin);
        $this->assertSame(70, $service->calculateAvailablePoints($agent));

        // The whole point of BR-7 Option B: redemption spends against
        // reward_point_ledger's aggregate via reward_redemptions.points_spent
        // reservation, never by writing to xp_ledger or mutating
        // reward_point_ledger itself.
        $this->assertSame($xpBefore, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));
        $this->assertSame($rewardPointRowsBefore, RewardPointLedger::where('user_id', $agent->id)->count());
        $this->assertSame($levelBefore, app(LevelService::class)->currentLevelForUser($agent));
    }

    public function test_calculate_available_points_subtracts_pending_approved_fulfilled_but_ignores_rejected(): void
    {
        $this->seedDefaultRule(GamificationSourceType::ModuleCompleted, 100);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        app(GamificationService::class)->awardXp($agent, GamificationSourceType::ModuleCompleted, 1);

        $item = RewardItem::create([
            'company_id' => $company->id,
            'name' => 'Voucher',
            'description' => null,
            'cost_points' => 10,
            'stock_quantity' => null,
            'is_active' => true,
            'reward_type' => RewardType::Digital,
        ]);

        $service = app(RewardRedemptionService::class);

        $pending = $service->requestRedemption($item, $agent);
        $toApprove = $service->requestRedemption($item, $agent);
        $toFulfill = $service->requestRedemption($item, $agent);
        $toReject = $service->requestRedemption($item, $agent);

        $service->decide($toApprove, RedemptionStatus::Approved, $admin);
        $service->decide($toFulfill, RedemptionStatus::Approved, $admin);
        $service->decide($toFulfill->fresh(), RedemptionStatus::Fulfilled, $admin);
        $service->decide($toReject, RedemptionStatus::Rejected, $admin);

        // 100 total - (10 pending + 10 approved + 10 fulfilled) = 70.
        // Rejected's 10 is excluded entirely — released back to the balance.
        $this->assertSame(70, $service->calculateAvailablePoints($agent));
        $this->assertSame(RedemptionStatus::Pending, $pending->fresh()->status);
    }
}
