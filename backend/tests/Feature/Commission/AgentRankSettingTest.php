<?php

namespace Tests\Feature\Commission;

use App\Enums\AgentRankRecalculationFrequency;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-031 — same shape as CommissionBinarySettingTest/CommissionMatrixSettingTest.
class AgentRankSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_or_update_agent_rank_settings(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/agent-rank-settings')->assertForbidden();
        $this->actingAs($agent)->putJson('/api/v1/agent-rank-settings', [
            'trailing_window_days' => 90, 'recalculation_frequency' => AgentRankRecalculationFrequency::Weekly->value,
        ])->assertForbidden();
    }

    public function test_show_returns_no_content_when_not_yet_configured(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson('/api/v1/agent-rank-settings')->assertNoContent();
    }

    /**
     * TASK-034 QA gap-fill — same regression-lock as CommissionMatrixSettingTest.
     *
     * 2026-09-11 — the WRITE half could not survive the owner's
     * Super-Admin-only commission-rate decision, so it is asserted as the
     * refusal it became; the READ half keeps the original subject, that a
     * Company Admin's company_id is silently ignored rather than honoured.
     */
    public function test_company_admin_company_id_param_is_ignored_on_read_and_the_write_is_refused(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/agent-rank-settings', [
            'trailing_window_days' => 90,
            'recalculation_frequency' => AgentRankRecalculationFrequency::Weekly->value,
            'company_id' => $companyB->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('agent_rank_settings', 0);

        // Seeded through the real endpoint by the only role that may write it.
        $this->actingAs(User::factory()->superAdmin()->create())->putJson('/api/v1/agent-rank-settings', [
            'company_id' => $companyA->id,
            'trailing_window_days' => 90,
            'recalculation_frequency' => AgentRankRecalculationFrequency::Weekly->value,
        ])->assertCreated();

        $this->assertDatabaseHas('agent_rank_settings', ['company_id' => $companyA->id, 'trailing_window_days' => 90]);
        $this->assertDatabaseMissing('agent_rank_settings', ['company_id' => $companyB->id]);

        $this->actingAs($adminA)
            ->getJson("/api/v1/agent-rank-settings?company_id={$companyB->id}")
            ->assertOk()
            ->assertJsonPath('data.trailing_window_days', 90);
    }

    /**
     * 2026-09-11 (owner decision) — THIS TEST IS AN INVERSION. It used to be
     * test_company_admin_can_configure_agent_rank_settings and asserted 201.
     *
     * Ability::SettingsAgentRankUpdate left the Company Admin row of
     * PermissionResolver along with the rest of the rate family — the rank
     * ladder these settings drive carries the rates it pays. THE COST: a
     * Company Admin can no longer choose how often their own ranks
     * recalculate, or over what trailing window.
     *
     * The read is asserted alongside on purpose: writes narrowed, reads did
     * not.
     */
    public function test_company_admin_can_no_longer_configure_agent_rank_settings_but_still_reads_them(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/agent-rank-settings', [
                'trailing_window_days' => 90, 'recalculation_frequency' => AgentRankRecalculationFrequency::Weekly->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('agent_rank_settings', 0);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/agent-rank-settings', [
                'company_id' => $company->id,
                'trailing_window_days' => 90, 'recalculation_frequency' => AgentRankRecalculationFrequency::Weekly->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.trailing_window_days', 90);

        $this->actingAs($admin)
            ->getJson('/api/v1/agent-rank-settings')
            ->assertOk()
            ->assertJsonPath('data.trailing_window_days', 90);
    }
}
