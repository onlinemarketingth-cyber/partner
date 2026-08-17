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

    public function test_company_admin_can_create_an_agent_rank(): void
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
