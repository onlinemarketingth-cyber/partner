<?php

namespace Tests\Feature\Referral;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-032 — same shape as AgentRankSettingTest/CommissionMatrixSettingTest.
class AffiliateAttributionSettingTest extends TestCase
{
    use RefreshDatabase;

    // TASK-033 gap-fill: an Agent may now READ this setting (the "My
    // Affiliate Links" screen shows the attribution window read-only)
    // but still cannot UPDATE it — that stays Company Admin/Super Admin
    // only, enforced by UpdateAffiliateAttributionSettingRequest::authorize().
    public function test_agent_can_view_but_not_update_attribution_settings(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/affiliate-attribution-settings', ['attribution_window_days' => 30])
            ->assertCreated();

        $this->actingAs($agent)
            ->getJson('/api/v1/affiliate-attribution-settings')
            ->assertOk()
            ->assertJsonPath('data.attribution_window_days', 30);

        $this->actingAs($agent)
            ->putJson('/api/v1/affiliate-attribution-settings', ['attribution_window_days' => 45])
            ->assertForbidden();
    }

    // TASK-034 QA gap-fill — same regression-lock as CommissionMatrixSettingTest
    // (this singleton family's shared "company_id from acting admin, query
    // param only honored for Super Admin" shape).
    public function test_company_admin_company_id_param_is_ignored_scoped_to_own_company_only(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/affiliate-attribution-settings', [
            'attribution_window_days' => 14,
            'company_id' => $companyB->id,
        ])->assertCreated();

        $this->assertDatabaseHas('affiliate_attribution_settings', ['company_id' => $companyA->id, 'attribution_window_days' => 14]);
        $this->assertDatabaseMissing('affiliate_attribution_settings', ['company_id' => $companyB->id]);
    }

    public function test_agent_sees_no_content_when_not_yet_configured(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/affiliate-attribution-settings')->assertNoContent();
    }

    public function test_show_returns_no_content_when_not_yet_configured(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson('/api/v1/affiliate-attribution-settings')->assertNoContent();
    }

    public function test_company_admin_can_configure_attribution_settings(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/affiliate-attribution-settings', ['attribution_window_days' => 30])
            ->assertCreated()
            ->assertJsonPath('data.attribution_window_days', 30);

        $this->actingAs($admin)
            ->getJson('/api/v1/affiliate-attribution-settings')
            ->assertOk()
            ->assertJsonPath('data.attribution_window_days', 30);
    }
}
