<?php

namespace Tests\Feature\Commission;

use App\Models\BinaryMatchingCycle;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-011/TASK-029 — read-only cycle history, "own or admin" shape
// (Section 5 rule 4), same pattern as CommissionLedgerController::index.
class BinaryMatchingCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_only_sees_their_own_cycles(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $otherAgent = User::factory()->agent()->create(['company_id' => $company->id]);

        BinaryMatchingCycle::factory()->create([
            'company_id' => $company->id, 'agent_id' => $agent->id,
            'period_start' => '2026-01-01', 'period_end' => '2026-01-07',
            'left_volume_satang' => 1000, 'right_volume_satang' => 1000, 'matched_volume_satang' => 1000,
        ]);
        BinaryMatchingCycle::factory()->create([
            'company_id' => $company->id, 'agent_id' => $otherAgent->id,
            'period_start' => '2026-01-01', 'period_end' => '2026-01-07',
            'left_volume_satang' => 2000, 'right_volume_satang' => 2000, 'matched_volume_satang' => 2000,
        ]);

        $this->actingAs($agent)
            ->getJson('/api/v1/binary-matching-cycles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent_id', $agent->id);
    }

    public function test_company_admin_can_filter_by_agent_id(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);

        BinaryMatchingCycle::factory()->create([
            'company_id' => $company->id, 'agent_id' => $agentA->id,
            'period_start' => '2026-01-01', 'period_end' => '2026-01-07',
            'left_volume_satang' => 1000, 'right_volume_satang' => 1000, 'matched_volume_satang' => 1000,
        ]);
        BinaryMatchingCycle::factory()->create([
            'company_id' => $company->id, 'agent_id' => $agentB->id,
            'period_start' => '2026-01-01', 'period_end' => '2026-01-07',
            'left_volume_satang' => 2000, 'right_volume_satang' => 2000, 'matched_volume_satang' => 2000,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/binary-matching-cycles?agent_id={$agentA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.agent_id', $agentA->id);
    }
}
