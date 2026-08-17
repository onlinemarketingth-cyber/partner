<?php

namespace Tests\Feature\Commission;

use App\Enums\BinaryCycleFrequency;
use App\Enums\CommissionRateType;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-029 — same "sensitive compensation config, Agent
// excluded entirely" access shape as CommissionRuleTest/
// CommissionOverrideRuleTest.
class CommissionBinarySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_or_update_binary_settings(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/commission-binary-settings')->assertForbidden();
        $this->actingAs($agent)->putJson('/api/v1/commission-binary-settings', [
            'matched_rate_type' => CommissionRateType::Percentage->value,
            'matched_rate_value' => 500,
            'cycle_frequency' => BinaryCycleFrequency::Weekly->value,
        ])->assertForbidden();
    }

    public function test_show_returns_no_content_when_not_yet_configured(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson('/api/v1/commission-binary-settings')->assertNoContent();
    }

    public function test_company_admin_can_configure_binary_settings(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // First PUT for this company CREATES the row (updateOrCreate) —
        // Laravel's JsonResource auto-sets 201 (not 200) whenever the
        // underlying model's wasRecentlyCreated is true, same as any
        // other Eloquent-model resource response in this codebase.
        $this->actingAs($admin)
            ->putJson('/api/v1/commission-binary-settings', [
                'matched_rate_type' => CommissionRateType::Percentage->value,
                'matched_rate_value' => 800,
                'cycle_frequency' => BinaryCycleFrequency::Monthly->value,
                'payout_cap_satang' => 500000,
                'carry_over_unmatched' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.matched_rate_value', 800)
            ->assertJsonPath('data.cycle_frequency', 'monthly')
            ->assertJsonPath('data.carry_over_unmatched', false);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-binary-settings')
            ->assertOk()
            ->assertJsonPath('data.matched_rate_value', 800);
    }

    // TASK-034 QA gap-fill — same regression-lock as CommissionMatrixSettingTest.
    public function test_company_admin_company_id_param_is_ignored_scoped_to_own_company_only(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/commission-binary-settings', [
            'matched_rate_type' => CommissionRateType::Percentage->value,
            'matched_rate_value' => 500,
            'cycle_frequency' => BinaryCycleFrequency::Weekly->value,
            'company_id' => $companyB->id,
        ])->assertCreated();

        $this->assertDatabaseHas('commission_binary_settings', ['company_id' => $companyA->id, 'matched_rate_value' => 500]);
        $this->assertDatabaseMissing('commission_binary_settings', ['company_id' => $companyB->id]);

        $this->actingAs($adminA)
            ->getJson("/api/v1/commission-binary-settings?company_id={$companyB->id}")
            ->assertOk()
            ->assertJsonPath('data.matched_rate_value', 500);
    }

    public function test_updating_twice_upserts_the_same_row_not_a_second_one(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $payload = [
            'matched_rate_type' => CommissionRateType::Percentage->value,
            'matched_rate_value' => 500,
            'cycle_frequency' => BinaryCycleFrequency::Weekly->value,
        ];

        // Same 201-on-create / 200-on-update distinction as the test
        // above — first call creates the row, second updates it.
        $this->actingAs($admin)->putJson('/api/v1/commission-binary-settings', $payload)->assertCreated();
        $this->actingAs($admin)->putJson('/api/v1/commission-binary-settings', [...$payload, 'matched_rate_value' => 900])->assertOk();

        $this->assertDatabaseCount('commission_binary_settings', 1);
        $this->assertDatabaseHas('commission_binary_settings', ['company_id' => $company->id, 'matched_rate_value' => 900]);
    }
}
