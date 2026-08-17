<?php

namespace Tests\Feature\Gamification;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Phase 10 — Badge authoring (previously index-only/seed-only). Same
// "company override or platform default" shape as GamificationRuleTest.
class BadgeCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_view_badges_but_cannot_create_one(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/badges')->assertOk();
        $this->actingAs($agent)
            ->postJson('/api/v1/badges', ['key' => 'x', 'name' => 'X', 'description' => 'd', 'icon' => 'star'])
            ->assertForbidden();
    }

    public function test_company_admin_can_create_a_badge_scoped_to_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/badges', ['key' => 'own_company_badge', 'name' => 'Own', 'description' => 'd', 'icon' => 'star'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $company->id);
    }

    public function test_company_admin_cannot_set_company_id_to_null_or_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/badges', ['company_id' => $otherCompany->id, 'key' => 'x', 'name' => 'X', 'description' => 'd', 'icon' => 'star'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_super_admin_can_create_a_platform_wide_badge_with_condition_config(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/badges', [
                'company_id' => null,
                'key' => 'xp_500',
                'name' => 'XP 500',
                'description' => 'd',
                'icon' => 'star',
                'condition_config' => [['metric' => 'xp_total', 'operator' => '>=', 'value' => 500]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.company_id', null)
            ->assertJsonPath('data.condition_config.0.metric', 'xp_total');
    }

    public function test_condition_config_rejects_an_unsupported_metric(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/badges', [
                'key' => 'bad',
                'name' => 'Bad',
                'description' => 'd',
                'icon' => 'star',
                'condition_config' => [['metric' => 'made_up_metric', 'operator' => '>=', 'value' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('condition_config.0.metric');
    }

    public function test_condition_config_rejects_an_unsupported_operator(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/badges', [
                'key' => 'bad2',
                'name' => 'Bad2',
                'description' => 'd',
                'icon' => 'star',
                'condition_config' => [['metric' => 'xp_total', 'operator' => '!=', 'value' => 1]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('condition_config.0.operator');
    }

    public function test_company_admin_cannot_edit_a_platform_default_badge(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $superAdmin = User::factory()->superAdmin()->create();
        $badgeId = $this->actingAs($superAdmin)
            ->postJson('/api/v1/badges', ['company_id' => null, 'key' => 'default_badge', 'name' => 'D', 'description' => 'd', 'icon' => 'star'])
            ->json('data.id');

        $this->actingAs($admin)
            ->putJson("/api/v1/badges/{$badgeId}", ['name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_super_admin_can_delete_a_badge(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $badgeId = $this->actingAs($superAdmin)
            ->postJson('/api/v1/badges', ['key' => 'deletable', 'name' => 'D', 'description' => 'd', 'icon' => 'star'])
            ->json('data.id');

        $this->actingAs($superAdmin)->deleteJson("/api/v1/badges/{$badgeId}")->assertNoContent();
        $this->assertDatabaseMissing('badges', ['id' => $badgeId]);
    }
}
