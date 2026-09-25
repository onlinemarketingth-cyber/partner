<?php

namespace Tests\Feature\Commission;

use App\Enums\AgentRankRecalculationFrequency;
use App\Enums\AgentRankVolumeScope;
use App\Enums\CommissionPlanType;
use App\Models\AgentRank;
use App\Models\AgentRankSetting;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `commissions:recalculate-agent-ranks --company=` (2026-09-25, UAT-017).
 *
 * The owner runs this by hand on the shared production host to rank a UAT
 * company. The bare command re-ranks EVERY company that happens to be due,
 * and "rank my test company now" is not a reason to move a real company's
 * agents — so the flag's one promise is that nothing else is touched.
 */
class RecalculateAgentRanksCommandTest extends TestCase
{
    use RefreshDatabase;

    /** A company with a single ฿0 rung, so every agent ranks the moment it runs. */
    private function rankedCompany(): array
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::StairstepBreakaway]);
        AgentRankSetting::create([
            'company_id' => $company->id,
            'trailing_window_days' => 90,
            'volume_scope' => AgentRankVolumeScope::Personal,
            'recalculation_frequency' => AgentRankRecalculationFrequency::Daily,
        ]);
        $rung = AgentRank::factory()->create(['company_id' => $company->id, 'volume_threshold' => 0]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        return [$company, $rung, $agent];
    }

    public function test_it_ranks_only_the_named_company(): void
    {
        [$uat, $uatRung, $uatAgent] = $this->rankedCompany();
        [, , $realAgent] = $this->rankedCompany();

        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => $uat->id])
            ->expectsOutputToContain('No other company was touched')
            ->assertSuccessful();

        $this->assertSame($uatRung->id, $uatAgent->fresh()->current_rank_id);
        // Due, and left alone: that is the whole point of the flag.
        $this->assertNull($realAgent->fresh()->current_rank_id);
    }

    public function test_the_bare_command_still_sweeps_every_due_company(): void
    {
        // The schedule passes no flag. Its behaviour must not have moved.
        [, $rungA, $agentA] = $this->rankedCompany();
        [, $rungB, $agentB] = $this->rankedCompany();

        $this->artisan('commissions:recalculate-agent-ranks')->assertSuccessful();

        $this->assertSame($rungA->id, $agentA->fresh()->current_rank_id);
        $this->assertSame($rungB->id, $agentB->fresh()->current_rank_id);
    }

    public function test_a_second_run_inside_the_cadence_says_why_it_did_nothing(): void
    {
        // "Recalculated rank for 0 agent(s)" is the sentence that gets
        // reported as a broken job. UAT-017 hits exactly this on day one.
        [$company] = $this->rankedCompany();

        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => $company->id])->assertSuccessful();
        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => $company->id])
            ->expectsOutputToContain('not due yet')
            ->expectsOutputToContain('Next allowed run')
            ->assertSuccessful();
    }

    public function test_the_scheduled_sweep_respects_the_cadence_too(): void
    {
        /*
         * The gate lives in the service, not only in the command's pre-check
         * for --company. A mutation that removed it from the service left
         * every other test green, because they all went through the flag —
         * and the schedule, which passes no flag, is what real companies run
         * on.
         */
        [, , $agent] = $this->rankedCompany();

        $this->artisan('commissions:recalculate-agent-ranks')->assertSuccessful();
        $agent->forceFill(['current_rank_id' => null])->save();

        $this->artisan('commissions:recalculate-agent-ranks')
            ->expectsOutputToContain('Recalculated rank for 0 agent(s)')
            ->assertSuccessful();

        $this->assertNull($agent->fresh()->current_rank_id, 'a daily company must not be re-ranked twice in a day');
    }

    public function test_it_runs_again_once_the_cadence_has_passed(): void
    {
        [$company, , $agent] = $this->rankedCompany();

        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => $company->id])->assertSuccessful();
        $agent->forceFill(['current_rank_id' => null])->save();

        $this->travel(25)->hours();

        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => $company->id])
            ->expectsOutputToContain('Recalculated rank for 1 agent(s)')
            ->assertSuccessful();
        $this->assertNotNull($agent->fresh()->current_rank_id);
    }

    public function test_it_accepts_the_slug_the_operator_can_see_on_screen(): void
    {
        [$company, $rung, $agent] = $this->rankedCompany();

        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => $company->slug])
            ->expectsOutputToContain('No other company was touched')
            ->assertSuccessful();

        $this->assertSame($rung->id, $agent->fresh()->current_rank_id);
    }

    public function test_an_unknown_company_is_refused_not_reported_as_done(): void
    {
        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => 999999])
            ->expectsOutputToContain('No company with id or slug "999999"')
            ->assertFailed();

        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => 'uat-plan-nobody'])
            ->expectsOutputToContain('No company with id or slug "uat-plan-nobody"')
            ->assertFailed();
    }

    public function test_a_company_with_no_rank_settings_is_told_where_to_set_them(): void
    {
        $company = Company::factory()->create();

        $this->artisan('commissions:recalculate-agent-ranks', ['--company' => $company->id])
            ->expectsOutputToContain('has no rank settings')
            ->assertFailed();
    }
}
