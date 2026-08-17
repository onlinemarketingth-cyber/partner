<?php

namespace Tests\Feature\Gamification;

use App\Models\Company;
use App\Models\LevelThreshold;
use App\Models\User;
use App\Models\XpLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// BR-5 — standalone ranked aggregate, scoped per-company (Section 5).
// Phase 9: level_number/next_level_xp_required are now included per
// row, computed by LevelService from the Admin-configured
// level_thresholds table (BR-7) — never a hardcoded formula.
class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaderboard_ranks_agents_by_total_xp_descending(): void
    {
        $company = Company::factory()->create();
        $agentLow = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentHigh = User::factory()->agent()->create(['company_id' => $company->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agentLow->id, 'xp_awarded' => 10]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agentHigh->id, 'xp_awarded' => 90]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agentHigh->id, 'xp_awarded' => 20]);

        $response = $this->actingAs($agentLow)->getJson('/api/v1/leaderboard')->assertOk();

        $response->assertJsonPath('data.0.user.id', $agentHigh->id)
            ->assertJsonPath('data.0.total_xp', 110)
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.1.user.id', $agentLow->id)
            ->assertJsonPath('data.1.total_xp', 10)
            ->assertJsonPath('data.1.rank', 2);
    }

    public function test_leaderboard_computes_level_number_from_level_thresholds(): void
    {
        LevelThreshold::create(['level_number' => 1, 'xp_required' => 0]);
        LevelThreshold::create(['level_number' => 2, 'xp_required' => 100]);
        LevelThreshold::create(['level_number' => 3, 'xp_required' => 300]);

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agent->id, 'xp_awarded' => 150]);

        $response = $this->actingAs($agent)->getJson('/api/v1/leaderboard')->assertOk();

        $response->assertJsonPath('data.0.level_number', 2)
            ->assertJsonPath('data.0.next_level_xp_required', 300);
    }

    public function test_leaderboard_level_number_defaults_to_zero_when_no_thresholds_configured(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agent->id, 'xp_awarded' => 50]);

        $response = $this->actingAs($agent)->getJson('/api/v1/leaderboard')->assertOk();

        $response->assertJsonPath('data.0.level_number', 0)
            ->assertJsonPath('data.0.next_level_xp_required', null);
    }

    public function test_leaderboard_does_not_include_agents_from_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        User::factory()->agent()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($agent)->getJson('/api/v1/leaderboard')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_super_admin_must_specify_a_company_id(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->getJson('/api/v1/leaderboard')->assertUnprocessable();
    }

    public function test_super_admin_can_view_a_specific_companys_leaderboard(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agent->id, 'xp_awarded' => 40]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->getJson("/api/v1/leaderboard?company_id={$company->id}")
            ->assertOk()
            ->assertJsonPath('data.0.total_xp', 40);
    }
}
