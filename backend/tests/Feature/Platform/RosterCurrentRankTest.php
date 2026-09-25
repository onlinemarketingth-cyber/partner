<?php

namespace Tests\Feature\Platform;

use App\Enums\CommissionPlanType;
use App\Models\AgentRank;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The roster's "ขั้นปัจจุบัน" (2026-09-25).
 *
 * Owner, planning UAT-017: no screen showed which rung an agent held. On
 * Stairstep the rung IS the agent's own pay (ADR-043), so an admin checking
 * whether the ranking job did the right thing had to reverse-engineer it
 * from the size of commission rows.
 *
 * Three states have to be told apart, and they are the whole contract:
 *
 *   · a rank plan, agent ranked     → `current_rank` is the rung
 *   · a rank plan, agent not ranked → `current_rank` is null (every agent
 *                                     starts here until the job first runs)
 *   · a plan with no ranks          → the key is absent
 */
class RosterCurrentRankTest extends TestCase
{
    use RefreshDatabase;

    private function companyOn(CommissionPlanType $plan): Company
    {
        return Company::factory()->create(['commission_plan_type' => $plan]);
    }

    /** @return array<int, array<string, mixed>> */
    private function roster(User $viewer): array
    {
        return $this->actingAs($viewer)->getJson('/api/v1/users')->assertOk()->json('data');
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function row(array $rows, User $user): array
    {
        $row = collect($rows)->firstWhere('id', $user->id);
        $this->assertNotNull($row, "user {$user->id} is not on the roster");

        return $row;
    }

    public function test_a_ranked_stairstep_agent_shows_their_rung(): void
    {
        $company = $this->companyOn(CommissionPlanType::StairstepBreakaway);
        $rung = AgentRank::factory()->create(['company_id' => $company->id, 'name' => 'ผู้จัดการ', 'is_breakaway_rank' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $agent->forceFill(['current_rank_id' => $rung->id])->save();

        $row = $this->row($this->roster(User::factory()->companyAdmin()->create(['company_id' => $company->id])), $agent);

        $this->assertSame(['id' => $rung->id, 'name' => 'ผู้จัดการ', 'is_breakaway' => true], $row['current_rank']);
    }

    public function test_an_unranked_agent_on_a_rank_plan_says_so_rather_than_saying_nothing(): void
    {
        // Null, present — "this plan has ranks and this agent holds none yet".
        // Every agent is here until the scheduled job first runs, and it is
        // precisely what UAT has to be able to see.
        $company = $this->companyOn(CommissionPlanType::StairstepBreakaway);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $row = $this->row($this->roster(User::factory()->companyAdmin()->create(['company_id' => $company->id])), $agent);

        $this->assertArrayHasKey('current_rank', $row);
        $this->assertNull($row['current_rank']);
    }

    public function test_a_generation_company_shows_ranks_too(): void
    {
        // Generation reads the breakaway flag to find where a generation
        // begins, and its ranks come from the same job.
        $company = $this->companyOn(CommissionPlanType::Generation);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $row = $this->row($this->roster(User::factory()->companyAdmin()->create(['company_id' => $company->id])), $agent);

        $this->assertArrayHasKey('current_rank', $row);
    }

    public function test_a_plan_without_ranks_carries_no_rank_key_at_all(): void
    {
        $company = $this->companyOn(CommissionPlanType::Unilevel);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $row = $this->row($this->roster(User::factory()->companyAdmin()->create(['company_id' => $company->id])), $agent);

        $this->assertArrayNotHasKey('current_rank', $row);
    }

    public function test_a_rung_belonging_to_another_company_never_reaches_this_roster(): void
    {
        /*
         * BR-6. current_rank_id pointing across tenants would be corrupt
         * data, and the one thing a roster must not do with corrupt data is
         * print another company's rung name beside this company's agent.
         * The eager load runs under TenantScope, so it resolves to nothing.
         */
        $company = $this->companyOn(CommissionPlanType::StairstepBreakaway);
        $foreign = AgentRank::factory()->create([
            'company_id' => $this->companyOn(CommissionPlanType::StairstepBreakaway)->id,
            'name' => 'ขั้นของบริษัทอื่น',
        ]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $agent->forceFill(['current_rank_id' => $foreign->id])->save();

        $rows = $this->roster(User::factory()->companyAdmin()->create(['company_id' => $company->id]));

        $this->assertNull($this->row($rows, $agent)['current_rank']);
        $this->assertStringNotContainsString('ขั้นของบริษัทอื่น', json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    public function test_an_agent_cannot_read_the_roster_to_learn_anyones_rank(): void
    {
        $company = $this->companyOn(CommissionPlanType::StairstepBreakaway);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/users')->assertForbidden();
    }
}
