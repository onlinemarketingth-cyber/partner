<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Models\AgentRank;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-031 — same "sensitive compensation config, Agent excluded
// entirely" access shape as CommissionOverrideRuleTest/CommissionMatrixLevelRateTest.
class AgentRankTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_view_agent_ranks(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->getJson('/api/v1/agent-ranks')
            ->assertForbidden();
    }

    /**
     * 2026-09-11 (owner decision) — THIS TEST IS AN INVERSION. It used to be
     * test_company_admin_can_create_an_agent_rank and asserted 201.
     *
     * A rank row carries a rate_type/rate_value, not just a display label, so
     * it moved with the rest of the rate family when the owner decided
     * commission rate configuration is Super Admin's alone — a rate is money.
     *
     * THE COST, and it is wider than it looks: a Company Admin can no longer
     * build or reshape their own rank LADDER at all. That is not only the
     * rate — the name, volume_threshold, sort_order and is_breakaway_rank all
     * live on the same row and the Policy does not (and should not) split
     * them, so renaming "Bronze" to "ทองแดง" is now a Super Admin's job too.
     * Accepted knowingly: leaving a writable half of the row would leave a
     * writable half of the rate.
     */
    public function test_company_admin_can_no_longer_create_an_agent_rank(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/agent-ranks', [
                'name' => 'Bronze',
                'volume_threshold' => 500_000,
                'sort_order' => 1,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 200,
                'is_breakaway_rank' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('agent_ranks', 0);
    }

    /** The other half of the decision: the capability moved, it did not vanish. */
    public function test_super_admin_can_create_an_agent_rank(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/agent-ranks', [
                'company_id' => $company->id,
                'name' => 'Bronze',
                'volume_threshold' => 500_000,
                'sort_order' => 1,
                'rate_type' => CommissionRateType::Percentage->value,
                'rate_value' => 200,
                'is_breakaway_rank' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Bronze');
    }

    public function test_company_admin_cannot_view_another_companys_agent_rank(): void
    {
        // BR-6 — same cross-tenant guard shape as every other Policy in this family.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $rank = AgentRank::factory()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminB)
            ->getJson("/api/v1/agent-ranks/{$rank->id}")
            ->assertNotFound();
    }

    public function test_company_admin_cannot_update_another_companys_agent_rank(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);
        $rank = AgentRank::factory()->create(['company_id' => $companyA->id]);

        $this->actingAs($adminB)
            ->putJson("/api/v1/agent-ranks/{$rank->id}", ['rate_value' => 999])
            ->assertNotFound();
    }
}
