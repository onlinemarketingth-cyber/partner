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

    /**
     * 2026-09-11 (owner decision) — THIS TEST IS AN INVERSION. It used to be
     * test_company_admin_can_configure_binary_settings and asserted 201 on
     * the write.
     *
     * Ability::SettingsCommissionBinaryUpdate left the Company Admin row of
     * PermissionResolver: the matched rate is money, and the owner decided
     * one person owns it. THE COST: a Company Admin can no longer set their
     * own binary matched rate, payout cap, cycle cadence or carry-over
     * policy, and BinaryCommissionService will not process their company
     * until the platform owner configures it for them.
     *
     * The READ half is asserted in the same test on purpose — it is the
     * shape of the decision. Writes narrowed; reads did not. A Company Admin
     * who cannot change the rate must still be able to see it, or they
     * cannot answer the first question an agent asks them.
     */
    public function test_company_admin_can_no_longer_configure_binary_settings_but_still_reads_them(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-binary-settings', [
                'matched_rate_type' => CommissionRateType::Percentage->value,
                'matched_rate_value' => 800,
                'cycle_frequency' => BinaryCycleFrequency::Monthly->value,
                'payout_cap_satang' => 500000,
                'carry_over_unmatched' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('commission_binary_settings', 0);

        // The platform owner configures it instead...
        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/commission-binary-settings', [
                'company_id' => $company->id,
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

        // ...and the Company Admin can still read every bit of it.
        $this->actingAs($admin)
            ->getJson('/api/v1/commission-binary-settings')
            ->assertOk()
            ->assertJsonPath('data.matched_rate_value', 800);
    }

    /**
     * TASK-034 QA gap-fill — same regression-lock as CommissionMatrixSettingTest.
     *
     * 2026-09-11 — the WRITE half of this test could not survive the owner's
     * decision (a Company Admin naming a foreign company_id on a PUT is now
     * refused outright, which proves nothing about BR-6 muting), so it is
     * asserted as the refusal it became. The READ half is the part that still
     * carries the original subject and is unchanged: a Company Admin's
     * ?company_id is ignored, not honoured and not rejected, and they are
     * answered about their own tenant.
     */
    public function test_company_admin_company_id_param_is_ignored_on_read_and_the_write_is_refused(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/commission-binary-settings', [
            'matched_rate_type' => CommissionRateType::Percentage->value,
            'matched_rate_value' => 500,
            'cycle_frequency' => BinaryCycleFrequency::Weekly->value,
            'company_id' => $companyB->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('commission_binary_settings', 0);

        // Seeded through the real endpoint by the only role that may write
        // it, so the read below is exercising a genuine row.
        $this->actingAs(User::factory()->superAdmin()->create())->putJson('/api/v1/commission-binary-settings', [
            'company_id' => $companyA->id,
            'matched_rate_type' => CommissionRateType::Percentage->value,
            'matched_rate_value' => 500,
            'cycle_frequency' => BinaryCycleFrequency::Weekly->value,
        ])->assertCreated();

        $this->assertDatabaseHas('commission_binary_settings', ['company_id' => $companyA->id, 'matched_rate_value' => 500]);
        $this->assertDatabaseMissing('commission_binary_settings', ['company_id' => $companyB->id]);

        $this->actingAs($adminA)
            ->getJson("/api/v1/commission-binary-settings?company_id={$companyB->id}")
            ->assertOk()
            ->assertJsonPath('data.matched_rate_value', 500);
    }

    /**
     * Actor switched to Super Admin on 2026-09-11; the subject is
     * updateOrCreate's single-row guarantee, not who may write.
     */
    public function test_updating_twice_upserts_the_same_row_not_a_second_one(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();
        $payload = [
            'company_id' => $company->id,
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
