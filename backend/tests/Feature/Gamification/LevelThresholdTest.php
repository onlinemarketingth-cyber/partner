<?php

namespace Tests\Feature\Gamification;

use App\Models\Company;
use App\Models\LevelThreshold;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Phase 9 — level_thresholds is platform-wide config (no company_id at
// all), so unlike GamificationRule/Badge there is no "own company vs
// platform default" split: it's simply Super-Admin-write, anyone-read.
class LevelThresholdTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_authenticated_user_can_view_level_thresholds(): void
    {
        LevelThreshold::create(['level_number' => 1, 'xp_required' => 0]);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/level-thresholds')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_company_admin_cannot_create_a_level_threshold(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/level-thresholds', ['level_number' => 2, 'xp_required' => 100])
            ->assertForbidden();
    }

    public function test_super_admin_can_create_a_level_threshold(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/level-thresholds', ['level_number' => 2, 'xp_required' => 100])
            ->assertCreated()
            ->assertJsonPath('data.level_number', 2)
            ->assertJsonPath('data.xp_required', 100);
    }

    public function test_duplicate_level_number_is_rejected(): void
    {
        LevelThreshold::create(['level_number' => 2, 'xp_required' => 100]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/level-thresholds', ['level_number' => 2, 'xp_required' => 200])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level_number');
    }

    public function test_super_admin_can_update_a_level_threshold(): void
    {
        $threshold = LevelThreshold::create(['level_number' => 2, 'xp_required' => 100]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/level-thresholds/{$threshold->id}", ['xp_required' => 150])
            ->assertOk()
            ->assertJsonPath('data.xp_required', 150);
    }

    public function test_company_admin_cannot_update_a_level_threshold(): void
    {
        $threshold = LevelThreshold::create(['level_number' => 2, 'xp_required' => 100]);
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/level-thresholds/{$threshold->id}", ['xp_required' => 150])
            ->assertForbidden();
    }

    public function test_super_admin_can_delete_a_level_threshold(): void
    {
        $threshold = LevelThreshold::create(['level_number' => 2, 'xp_required' => 100]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->deleteJson("/api/v1/level-thresholds/{$threshold->id}")->assertNoContent();
        $this->assertDatabaseMissing('level_thresholds', ['id' => $threshold->id]);
    }

    public function test_agent_cannot_delete_a_level_threshold(): void
    {
        $threshold = LevelThreshold::create(['level_number' => 2, 'xp_required' => 100]);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->deleteJson("/api/v1/level-thresholds/{$threshold->id}")->assertForbidden();
    }
}
