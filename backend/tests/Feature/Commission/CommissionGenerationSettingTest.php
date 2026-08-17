<?php

namespace Tests\Feature\Commission;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-031 — same shape as CommissionMatrixSettingTest.
class CommissionGenerationSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_or_update_generation_settings(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/commission-generation-settings')->assertForbidden();
        $this->actingAs($agent)->putJson('/api/v1/commission-generation-settings', ['max_generation_depth' => 5])->assertForbidden();
    }

    public function test_show_returns_no_content_when_not_yet_configured(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson('/api/v1/commission-generation-settings')->assertNoContent();
    }

    // TASK-034 QA gap-fill — same regression-lock as CommissionMatrixSettingTest.
    public function test_company_admin_company_id_param_is_ignored_scoped_to_own_company_only(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/commission-generation-settings', [
            'max_generation_depth' => 5,
            'company_id' => $companyB->id,
        ])->assertCreated();

        $this->assertDatabaseHas('commission_generation_settings', ['company_id' => $companyA->id, 'max_generation_depth' => 5]);
        $this->assertDatabaseMissing('commission_generation_settings', ['company_id' => $companyB->id]);
    }

    public function test_company_admin_can_configure_generation_settings(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-generation-settings', ['max_generation_depth' => 5])
            ->assertCreated()
            ->assertJsonPath('data.max_generation_depth', 5);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-generation-settings')
            ->assertOk()
            ->assertJsonPath('data.max_generation_depth', 5);
    }
}
