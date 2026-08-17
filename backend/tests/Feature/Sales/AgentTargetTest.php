<?php

namespace Tests\Feature\Sales;

use App\Models\AgentTarget;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// TASK-053 / ADR-016 Phase 1 — per-agent targets: Admin sets, agent reads
// own, re-setting updates the same (agent, period, metric) row, and an
// agent cannot set targets.
class AgentTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_set_a_target_and_agent_reads_it(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/agent-targets', [
            'agent_id' => $agent->id,
            'period' => '2026-08',
            'metric' => 'sales_satang',
            'target_value' => 5000000,
        ])->assertOk()->assertJsonPath('data.target_value', 5000000);

        $this->actingAs($agent)->getJson('/api/v1/me/targets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.metric', 'sales_satang')
            ->assertJsonPath('data.0.target_value', 5000000);
    }

    public function test_re_setting_updates_the_same_row(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $payload = ['agent_id' => $agent->id, 'period' => '2026-08', 'metric' => 'deals'];
        $this->actingAs($admin)->postJson('/api/v1/agent-targets', $payload + ['target_value' => 10])->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/agent-targets', $payload + ['target_value' => 20])->assertOk();

        $this->assertSame(1, AgentTarget::where('agent_id', $agent->id)->where('period', '2026-08')->where('metric', 'deals')->count());
        $this->assertSame(20, (int) AgentTarget::where('agent_id', $agent->id)->where('metric', 'deals')->first()->target_value);
    }

    public function test_agent_cannot_set_targets(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->postJson('/api/v1/agent-targets', [
            'agent_id' => $agent->id,
            'period' => '2026-08',
            'metric' => 'deals',
            'target_value' => 10,
        ])->assertForbidden();
    }

    public function test_admin_can_read_an_agents_targets(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        AgentTarget::create(['company_id' => $company->id, 'agent_id' => $agent->id, 'period' => '2026-08', 'metric' => 'deals', 'target_value' => 12]);

        $this->actingAs($admin)->getJson("/api/v1/agent-targets?agent_id={$agent->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.target_value', 12);
    }

    public function test_admin_cannot_read_a_foreign_companys_agent_targets(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        AgentTarget::create(['company_id' => $otherCompany->id, 'agent_id' => $foreignAgent->id, 'period' => '2026-08', 'metric' => 'deals', 'target_value' => 99]);

        // TenantScope narrows the admin to their own company → foreign
        // agent's targets are simply not visible (empty, not 403-leaky).
        $this->actingAs($admin)->getJson("/api/v1/agent-targets?agent_id={$foreignAgent->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_agent_cannot_read_targets_via_admin_endpoint(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson("/api/v1/agent-targets?agent_id={$agent->id}")
            ->assertForbidden();
    }

    // ── TASK-130 §4+§5 — a 4-character period is a YEARLY target ──

    public function test_admin_can_set_a_yearly_target(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/agent-targets', [
            'agent_id' => $agent->id,
            'period' => '2026',
            'metric' => 'sales_satang',
            'target_value' => 60000000,
        ])->assertOk()
            ->assertJsonPath('data.period', '2026')
            ->assertJsonPath('data.target_value', 60000000);
    }

    /**
     * The monthly and yearly targets for the same metric are two INDEPENDENT
     * rows: unique(agent_id, period, metric) treats '2026-08' and '2026' as
     * different periods, so setting one must never overwrite the other. This
     * is the whole reason the yearly target needed no schema change.
     */
    public function test_monthly_and_yearly_targets_coexist_for_the_same_metric(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/agent-targets', [
            'agent_id' => $agent->id, 'period' => '2026-08', 'metric' => 'deals', 'target_value' => 10,
        ])->assertOk();
        $this->actingAs($admin)->postJson('/api/v1/agent-targets', [
            'agent_id' => $agent->id, 'period' => '2026', 'metric' => 'deals', 'target_value' => 120,
        ])->assertOk();

        $this->assertSame(2, AgentTarget::where('agent_id', $agent->id)->where('metric', 'deals')->count());
        $this->assertSame(10, (int) AgentTarget::where('agent_id', $agent->id)->where('period', '2026-08')->where('metric', 'deals')->first()->target_value);
        $this->assertSame(120, (int) AgentTarget::where('agent_id', $agent->id)->where('period', '2026')->where('metric', 'deals')->first()->target_value);
    }

    /**
     * Widening the rule to accept 'YYYY' must not turn it into "any digits":
     * the shape is what tells a monthly target from a yearly one, so a
     * malformed period has to keep 422-ing rather than creating a third kind
     * of row nothing knows how to read.
     */
    public function test_a_malformed_period_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        foreach (['2026-8', '20268', '26-08', '2026-08-01', 'august'] as $period) {
            $this->actingAs($admin)->postJson('/api/v1/agent-targets', [
                'agent_id' => $agent->id, 'period' => $period, 'metric' => 'deals', 'target_value' => 10,
            ])->assertStatus(422)->assertJsonValidationErrors('period');
        }
    }

    /**
     * The Agent home goal ring reads THIS MONTH only (MeService::home filters
     * on Carbon::now()->format('Y-m')), so a yearly target must not leak into
     * it as a second, wildly larger "deals" goal.
     */
    public function test_yearly_target_does_not_appear_on_the_home_goal_ring(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $thisMonth = now()->format('Y-m');
        $thisYear = now()->format('Y');

        AgentTarget::create(['company_id' => $company->id, 'agent_id' => $agent->id, 'period' => $thisMonth, 'metric' => 'deals', 'target_value' => 10]);
        AgentTarget::create(['company_id' => $company->id, 'agent_id' => $agent->id, 'period' => $thisYear, 'metric' => 'deals', 'target_value' => 120]);

        $this->actingAs($agent)->getJson('/api/v1/me/home')
            ->assertOk()
            ->assertJsonCount(1, 'data.goals')
            ->assertJsonPath('data.goals.0.target_value', 10);
    }
}
