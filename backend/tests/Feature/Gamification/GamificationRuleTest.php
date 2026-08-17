<?php

namespace Tests\Feature\Gamification;

use App\Enums\GamificationSourceType;
use App\Models\Company;
use App\Models\GamificationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// BR-5 config CRUD. Same "Agent has zero read access" shape as
// CommissionRulePolicy, plus the extra nullable-company_id (platform
// default) behavior that CommissionRule doesn't have.
class GamificationRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_gamification_rules(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/gamification-rules')->assertForbidden();
    }

    public function test_company_admin_can_create_a_rule_scoped_to_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/gamification-rules', [
                'source_type' => GamificationSourceType::ModuleCompleted->value,
                'xp_value' => 25,
            ])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $company->id);
    }

    public function test_company_admin_cannot_set_company_id_to_null_or_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/gamification-rules', [
                'company_id' => $otherCompany->id,
                'source_type' => GamificationSourceType::ModuleCompleted->value,
                'xp_value' => 25,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_super_admin_can_create_a_platform_wide_default_rule(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/gamification-rules', [
                'company_id' => null,
                'source_type' => GamificationSourceType::ExamPassed->value,
                'xp_value' => 50,
            ])
            ->assertCreated()
            ->assertJsonPath('data.company_id', null);
    }

    public function test_creating_a_second_active_rule_for_the_same_company_and_source_type_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        GamificationRule::create(['company_id' => $company->id, 'source_type' => GamificationSourceType::ModuleCompleted, 'xp_value' => 10, 'is_active' => true]);

        $this->actingAs($admin)
            ->postJson('/api/v1/gamification-rules', [
                'source_type' => GamificationSourceType::ModuleCompleted->value,
                'xp_value' => 20,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_type');
    }

    public function test_creating_an_inactive_duplicate_rule_is_allowed(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        GamificationRule::create(['company_id' => $company->id, 'source_type' => GamificationSourceType::ModuleCompleted, 'xp_value' => 10, 'is_active' => true]);

        $this->actingAs($admin)
            ->postJson('/api/v1/gamification-rules', [
                'source_type' => GamificationSourceType::ModuleCompleted->value,
                'xp_value' => 20,
                'is_active' => false,
            ])
            ->assertCreated();
    }

    public function test_company_admin_index_sees_own_company_rules_and_platform_defaults_but_not_other_companies(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        GamificationRule::create(['company_id' => null, 'source_type' => GamificationSourceType::ModuleCompleted, 'xp_value' => 10, 'is_active' => true]);
        GamificationRule::create(['company_id' => $company->id, 'source_type' => GamificationSourceType::ExamPassed, 'xp_value' => 50, 'is_active' => true]);
        GamificationRule::create(['company_id' => $otherCompany->id, 'source_type' => GamificationSourceType::ReferralSubmitted, 'xp_value' => 20, 'is_active' => true]);

        $this->actingAs($admin)
            ->getJson('/api/v1/gamification-rules')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_cross_company_rule_access_is_rejected_for_company_admin(): void
    {
        // GamificationRule is deliberately NOT TenantScope'd (nullable
        // company_id must remain visible as the platform default), so
        // route-model-binding succeeds and the rejection happens at the
        // Policy (403), not via TenantScope filtering (404) — both are
        // valid per CLAUDE.md §5 rule 5 ("always expect 403/404").
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $foreignRule = GamificationRule::create(['company_id' => $otherCompany->id, 'source_type' => GamificationSourceType::ModuleCompleted, 'xp_value' => 10, 'is_active' => true]);

        $this->actingAs($admin)->getJson("/api/v1/gamification-rules/{$foreignRule->id}")->assertForbidden();
    }

    public function test_company_admin_cannot_edit_a_platform_default_rule(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $platformDefault = GamificationRule::create(['company_id' => null, 'source_type' => GamificationSourceType::ModuleCompleted, 'xp_value' => 10, 'is_active' => true]);

        $this->actingAs($admin)
            ->putJson("/api/v1/gamification-rules/{$platformDefault->id}", ['xp_value' => 999])
            ->assertForbidden();
    }
}
