<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Models\CommissionMatrixLevelRate;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-030 — mirrors CommissionOverrideRuleTest exactly (same
// access shape, same overlap-rejection behavior), keyed by level instead
// of manager_cert_tier_id.
//
// 2026-09-11 — the tests whose subject is the RULE (overlap, level
// independence) now act as a Super Admin: after the owner's decision only a
// Super Admin may write a commission rate, so a Company Admin 403s before
// the behaviour under test can run. Assertions unchanged; the capability the
// Company Admin lost is asserted directly below.
class CommissionMatrixLevelRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_matrix_level_rates(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/commission-matrix-level-rates')
            ->assertForbidden();
    }

    /**
     * 2026-09-11 (owner decision) — THIS TEST IS AN INVERSION. It used to be
     * test_company_admin_can_create_a_matrix_level_rate and asserted 201.
     *
     * A per-level matrix rate is a commission rate, so it moved with the rest
     * of the family when the owner decided rate configuration is Super
     * Admin's alone — a rate is money. THE COST: a Company Admin can no
     * longer decide what each level of their own matrix pays.
     */
    public function test_company_admin_can_no_longer_create_a_matrix_level_rate(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-matrix-level-rates', [
                'level' => 1,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 500,
                'effective_from' => now()->toDateString(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('commission_matrix_level_rates', 0);
    }

    /** The other half of the decision: the capability moved, it did not vanish. */
    public function test_super_admin_can_create_a_matrix_level_rate(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-matrix-level-rates', [
                'company_id' => $company->id,
                'level' => 1,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 500,
                'effective_from' => now()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.level', 1);
    }

    public function test_overlapping_effective_date_range_for_the_same_level_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->postJson('/api/v1/commission-matrix-level-rates', [
            'company_id' => $company->id,
            'level' => 1,
            'rate_type' => CommissionRateType::Percentage->value,
            'rate_value' => 500,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ])->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/v1/commission-matrix-level-rates', [
                'company_id' => $company->id,
                'level' => 1,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 800,
                'effective_from' => '2026-06-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_from');
    }

    // TASK-034 QA gap-fill — Section 5 rule 5 (IDOR): this is the one
    // resource in the TASK-030 Matrix family that's id-addressable
    // (view/update/delete by numeric id, unlike the width/depth/
    // spillover singleton) and had no cross-company test at all.
    public function test_company_admin_cannot_view_update_or_delete_another_companys_matrix_level_rate(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $rate = CommissionMatrixLevelRate::factory()->create(['company_id' => $companyA->id, 'level' => 1]);

        $this->actingAs($adminB)->getJson("/api/v1/commission-matrix-level-rates/{$rate->id}")->assertNotFound();
        $this->actingAs($adminB)->putJson("/api/v1/commission-matrix-level-rates/{$rate->id}", ['rate_value' => 999])->assertNotFound();
        $this->actingAs($adminB)->deleteJson("/api/v1/commission-matrix-level-rates/{$rate->id}")->assertNotFound();
        $this->actingAs($adminB)->getJson('/api/v1/commission-matrix-level-rates')->assertOk()->assertJsonMissing(['id' => $rate->id]);
    }

    public function test_different_levels_do_not_overlap_each_other(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->postJson('/api/v1/commission-matrix-level-rates', [
            'company_id' => $company->id,
            'level' => 1, 'rate_type' => CommissionRateType::Percentage->value, 'rate_value' => 500,
            'effective_from' => '2026-01-01',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/commission-matrix-level-rates', [
            'company_id' => $company->id,
            'level' => 2, 'rate_type' => CommissionRateType::Percentage->value, 'rate_value' => 300,
            'effective_from' => '2026-01-01',
        ])->assertCreated();
    }
}
