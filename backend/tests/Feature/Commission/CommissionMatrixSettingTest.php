<?php

namespace Tests\Feature\Commission;

use App\Enums\MatrixSpilloverRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-030 — same "sensitive compensation config, Agent
// excluded entirely" access shape as CommissionBinarySettingTest.
class CommissionMatrixSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_or_update_matrix_settings(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/commission-matrix-settings')->assertForbidden();
        $this->actingAs($agent)->putJson('/api/v1/commission-matrix-settings', [
            'width' => 3, 'depth' => 5, 'spillover_rule' => MatrixSpilloverRule::Breadth->value,
        ])->assertForbidden();
    }

    public function test_show_returns_no_content_when_not_yet_configured(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson('/api/v1/commission-matrix-settings')->assertNoContent();
    }

    // TASK-034 QA gap-fill — Section 5 rule 5: this singleton has no
    // {id} in its route, it resolves company_id from the acting admin
    // (Controller code), so the IDOR surface is narrower than a normal
    // resource — but nothing previously LOCKED IN that a non-Super-Admin
    // passing ?company_id=/company_id= for a different company is simply
    // ignored, not honored. A regression here would be silent (no crash,
    // just wrong data), so this needs its own assertion.
    /**
     * 2026-09-11 — the WRITE half of this test could not survive the owner's
     * Super-Admin-only commission-rate decision (a Company Admin's PUT is now
     * refused outright, which proves nothing about BR-6 muting), so it is
     * asserted as the refusal it became. The READ half still carries the
     * original subject and is unchanged: a Company Admin's ?company_id is
     * silently ignored, not honoured and not rejected.
     */
    public function test_company_admin_company_id_param_is_ignored_on_read_and_the_write_is_refused(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        // Seeded through the real endpoint by the only role that may write it.
        $this->actingAs(User::factory()->superAdmin()->create())->putJson('/api/v1/commission-matrix-settings', [
            'company_id' => $companyA->id,
            'width' => 2, 'depth' => 3, 'spillover_rule' => MatrixSpilloverRule::Breadth->value,
        ])->assertCreated();

        // Attempt to read company B's settings by smuggling its id in as a
        // Company Admin (not Super Admin) — must have zero effect.
        $this->actingAs($adminA)
            ->getJson("/api/v1/commission-matrix-settings?company_id={$companyB->id}")
            ->assertOk()
            ->assertJsonPath('data.width', 2); // still A's own row, not B's (which doesn't even exist)

        // ...and the write is refused before the smuggled id matters at all.
        $this->actingAs($adminA)->putJson('/api/v1/commission-matrix-settings', [
            'width' => 9, 'depth' => 9, 'spillover_rule' => MatrixSpilloverRule::Breadth->value,
            'company_id' => $companyB->id,
        ])->assertForbidden();

        $this->assertDatabaseHas('commission_matrix_settings', ['company_id' => $companyA->id, 'width' => 2]);
        $this->assertDatabaseMissing('commission_matrix_settings', ['company_id' => $companyB->id]);
    }

    /**
     * 2026-09-11 (owner decision) — THIS TEST IS AN INVERSION. It used to be
     * test_company_admin_can_configure_matrix_settings and asserted 201.
     *
     * Ability::SettingsCommissionMatrixUpdate left the Company Admin row of
     * PermissionResolver because matrix shape drives what the matrix pays,
     * and the owner decided one person owns the payout numbers. THE COST: a
     * Company Admin can no longer set their own matrix width, depth or
     * spillover rule.
     *
     * The read is asserted alongside on purpose: writes narrowed, reads did
     * not.
     */
    public function test_company_admin_can_no_longer_configure_matrix_settings_but_still_reads_them(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/commission-matrix-settings', [
                'width' => 3, 'depth' => 5, 'spillover_rule' => MatrixSpilloverRule::Breadth->value,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('commission_matrix_settings', 0);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/commission-matrix-settings', [
                'company_id' => $company->id,
                'width' => 3, 'depth' => 5, 'spillover_rule' => MatrixSpilloverRule::Breadth->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.width', 3)
            ->assertJsonPath('data.depth', 5);

        $this->actingAs($admin)
            ->getJson('/api/v1/commission-matrix-settings')
            ->assertOk()
            ->assertJsonPath('data.width', 3);
    }
}
