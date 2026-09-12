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
    // but still cannot UPDATE it — that stays Super Admin only since
    // 2026-09-11, enforced by
    // UpdateAffiliateAttributionSettingRequest::authorize().
    //
    // The SEEDING actor below is a Super Admin only because a Company Admin
    // may no longer write this row; the subject of the test is the Agent's
    // read-yes/write-no split, and both of its assertions are unchanged.
    public function test_agent_can_view_but_not_update_attribution_settings(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/affiliate-attribution-settings', [
                'company_id' => $company->id,
                'attribution_window_days' => 30,
            ])
            ->assertCreated();

        $this->actingAs($agent)
            ->getJson('/api/v1/affiliate-attribution-settings')
            ->assertOk()
            ->assertJsonPath('data.attribution_window_days', 30);

        $this->actingAs($agent)
            ->putJson('/api/v1/affiliate-attribution-settings', ['attribution_window_days' => 45])
            ->assertForbidden();
    }

    /**
     * TASK-034 QA gap-fill — same regression-lock as CommissionMatrixSettingTest
     * (this singleton family's shared "company_id from acting admin, query
     * param only honored for Super Admin" shape).
     *
     * 2026-09-11 — the WRITE half could not survive the owner's
     * Super-Admin-only commission-rate decision, so it is asserted as the
     * refusal it became; the READ half keeps the original subject.
     */
    public function test_company_admin_company_id_param_is_ignored_on_read_and_the_write_is_refused(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminA)->putJson('/api/v1/affiliate-attribution-settings', [
            'attribution_window_days' => 14,
            'company_id' => $companyB->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('affiliate_attribution_settings', 0);

        // Seeded through the real endpoint by the only role that may write it.
        $this->actingAs(User::factory()->superAdmin()->create())->putJson('/api/v1/affiliate-attribution-settings', [
            'company_id' => $companyA->id,
            'attribution_window_days' => 14,
        ])->assertCreated();

        $this->assertDatabaseHas('affiliate_attribution_settings', ['company_id' => $companyA->id, 'attribution_window_days' => 14]);
        $this->assertDatabaseMissing('affiliate_attribution_settings', ['company_id' => $companyB->id]);

        $this->actingAs($adminA)
            ->getJson("/api/v1/affiliate-attribution-settings?company_id={$companyB->id}")
            ->assertOk()
            ->assertJsonPath('data.attribution_window_days', 14);
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

    /**
     * 2026-09-11 (owner decision) — THIS TEST IS AN INVERSION. It used to be
     * test_company_admin_can_configure_attribution_settings and asserted 201.
     *
     * Ability::SettingsAffiliateAttributionUpdate left the Company Admin row
     * of PermissionResolver with the rest of the commission-rate group: the
     * attribution window decides WHICH affiliate gets paid for a sale, which
     * is a payout decision even though it is not a rate itself.
     *
     * THE COST, and it is the least obvious of the six: attribution window
     * length is the kind of thing a company tunes against its own sales
     * cycle, and it now needs the platform owner for every adjustment. The
     * new_vs_returning_rate_differential flag on the same endpoint goes with
     * it.
     *
     * The read is asserted alongside on purpose: writes narrowed, reads did
     * not — and here the read was already open all the way down to an Agent
     * (see the first test in this file), which is untouched.
     */
    public function test_company_admin_can_no_longer_configure_attribution_settings_but_still_reads_them(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson('/api/v1/affiliate-attribution-settings', ['attribution_window_days' => 30])
            ->assertForbidden();

        $this->assertDatabaseCount('affiliate_attribution_settings', 0);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/affiliate-attribution-settings', [
                'company_id' => $company->id,
                'attribution_window_days' => 30,
            ])
            ->assertCreated()
            ->assertJsonPath('data.attribution_window_days', 30);

        $this->actingAs($admin)
            ->getJson('/api/v1/affiliate-attribution-settings')
            ->assertOk()
            ->assertJsonPath('data.attribution_window_days', 30);
    }
}
