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

    /**
     * TASK-034 QA gap-fill — same regression-lock as CommissionMatrixSettingTest.
     *
     * 2026-09-11 — the WRITE half could not survive the owner's
     * Super-Admin-only commission-rate decision, so it is asserted as the
     * refusal it became; the READ half keeps the original subject, that a
     * Company Admin's ?company_id is silently ignored rather than honoured.
     */
    public function test_company_admin_company_id_param_is_ignored_on_read_and_the_write_is_refused(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/commission-generation-settings', [
            'max_generation_depth' => 5,
            'company_id' => $companyB->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('commission_generation_settings', 0);

        // Seeded through the real endpoint by the only role that may write it.
        $this->actingAs(User::factory()->superAdmin()->create())->putJson('/api/v1/commission-generation-settings', [
            'company_id' => $companyA->id,
            'max_generation_depth' => 5,
        ])->assertCreated();

        $this->assertDatabaseHas('commission_generation_settings', ['company_id' => $companyA->id, 'max_generation_depth' => 5]);
        $this->assertDatabaseMissing('commission_generation_settings', ['company_id' => $companyB->id]);

        $this->actingAs($adminA)
            ->getJson("/api/v1/commission-generation-settings?company_id={$companyB->id}")
            ->assertOk()
            ->assertJsonPath('data.max_generation_depth', 5);
    }

    /**
     * 2026-09-11 (owner decision) — THIS TEST IS AN INVERSION. It used to be
     * test_company_admin_can_configure_generation_settings and asserted 201.
     *
     * Ability::SettingsCommissionGenerationUpdate left the Company Admin row
     * of PermissionResolver: generation depth decides how far a payout
     * reaches, and the owner decided one person owns the payout numbers.
     * THE COST: a Company Admin can no longer set their own generation depth.
     *
     * The read is asserted alongside on purpose: writes narrowed, reads did
     * not.
     */
    public function test_company_admin_can_no_longer_configure_generation_settings_but_still_reads_them(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-generation-settings', ['max_generation_depth' => 5])
            ->assertForbidden();

        $this->assertDatabaseCount('commission_generation_settings', 0);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/commission-generation-settings', [
                'company_id' => $company->id,
                'max_generation_depth' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.max_generation_depth', 5);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-generation-settings')
            ->assertOk()
            ->assertJsonPath('data.max_generation_depth', 5);
    }
}
