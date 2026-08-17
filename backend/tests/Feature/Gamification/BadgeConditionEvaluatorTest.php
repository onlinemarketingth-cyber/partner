<?php

namespace Tests\Feature\Gamification;

use App\Enums\PipelineStage;
use App\Models\Company;
use App\Models\ModuleCompletion;
use App\Models\PipelineStageLog;
use App\Models\Referral;
use App\Models\User;
use App\Models\XpLedger;
use App\Services\Gamification\BadgeConditionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Phase 10 — pure interpreter logic (ERD-001 open question #9). Uses
// real DB rows since every supported metric is a DB aggregate query,
// not an in-memory value.
class BadgeConditionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private function evaluator(): BadgeConditionEvaluator
    {
        return app(BadgeConditionEvaluator::class);
    }

    public function test_empty_conditions_never_pass(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->assertFalse($this->evaluator()->evaluate([], $agent));
    }

    public function test_xp_total_condition_passes_when_threshold_met(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agent->id, 'xp_awarded' => 500]);

        $conditions = [['metric' => 'xp_total', 'operator' => '>=', 'value' => 500]];
        $this->assertTrue($this->evaluator()->evaluate($conditions, $agent));

        $conditions = [['metric' => 'xp_total', 'operator' => '>=', 'value' => 501]];
        $this->assertFalse($this->evaluator()->evaluate($conditions, $agent));
    }

    public function test_modules_completed_count_condition(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        ModuleCompletion::factory()->count(3)->create(['company_id' => $company->id, 'user_id' => $agent->id]);

        $conditions = [['metric' => 'modules_completed_count', 'operator' => '>=', 'value' => 3]];
        $this->assertTrue($this->evaluator()->evaluate($conditions, $agent));

        $conditions = [['metric' => 'modules_completed_count', 'operator' => '>=', 'value' => 4]];
        $this->assertFalse($this->evaluator()->evaluate($conditions, $agent));
    }

    public function test_referrals_completed_count_excludes_deals_that_have_not_reached_payment(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        Referral::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id, 'current_stage' => PipelineStage::CompletePayment]);
        Referral::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id, 'current_stage' => PipelineStage::WaitingAppointment]);

        $conditions = [['metric' => 'referrals_completed_count', 'operator' => '>=', 'value' => 1]];
        $this->assertTrue($this->evaluator()->evaluate($conditions, $agent));

        $conditions = [['metric' => 'referrals_completed_count', 'operator' => '>=', 'value' => 2]];
        $this->assertFalse($this->evaluator()->evaluate($conditions, $agent));
    }

    /**
     * TASK-180 §3 (B3) / §6 — a paid deal that moves FORWARD must not
     * remove itself from a BR-5 badge condition the agent had already
     * earned progress toward.
     *
     * This was the strictest of the five stale predicates:
     * `where('current_stage', CompletePayment)` exactly, so the moment
     * Admin advanced a paid deal into จัดส่ง the agent's
     * referrals_completed_count went DOWN. Restoring that comparison makes
     * the post-advance assertion fail.
     */
    public function test_badge_progress_does_not_decrease_when_a_paid_deal_advances(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'current_stage' => PipelineStage::CompletePayment,
        ]);
        // The audit row PipelineService::advance() writes when the payment
        // stage is reached — present for every application-written deal.
        PipelineStageLog::factory()->create([
            'company_id' => $company->id,
            'referral_id' => $referral->id,
            'from_stage' => PipelineStage::Finish1stDoctorMeeting,
            'to_stage' => PipelineStage::CompletePayment,
            'changed_by_user_id' => $agent->id,
        ]);

        $conditions = [['metric' => 'referrals_completed_count', 'operator' => '>=', 'value' => 1]];
        $this->assertTrue($this->evaluator()->evaluate($conditions, $agent), 'a paid deal must count');

        foreach ([PipelineStage::Delivery, PipelineStage::ServiceAppointment, PipelineStage::FollowUp, PipelineStage::OngoingNextMeeting] as $stage) {
            $referral->forceFill(['current_stage' => $stage])->save();

            $this->assertTrue(
                $this->evaluator()->evaluate($conditions, $agent),
                "progress must not drop when the deal advances to {$stage->value}",
            );
        }
    }

    /**
     * BR-6 / §5. NOT empty-vs-empty: the agent has one real closed deal of
     * their own, and one closed deal carrying their agent_id under ANOTHER
     * company — the shape a company move (Phase 11) or an import leaves
     * behind. Only their own company's deal may count.
     *
     * This runs with NO authenticated user on purpose: BadgeAutoAwardService
     * is driven by domain events and can execute in a queue worker or
     * console command, where TenantScope is a no-op. The explicit
     * company_id filter in BadgeConditionEvaluator is the only thing
     * standing here; delete it and the `>= 2` assertion below flips to
     * true.
     */
    public function test_referrals_completed_count_ignores_another_companys_deals(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        Referral::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'current_stage' => PipelineStage::Delivery,
        ]);
        Referral::factory()->create([
            'company_id' => $otherCompany->id,
            'agent_id' => $agent->id,
            'current_stage' => PipelineStage::Delivery,
        ]);

        // Both rows carry the payment event; only the tenant differs.
        foreach (Referral::withoutGlobalScopes()->where('agent_id', $agent->id)->get() as $referral) {
            PipelineStageLog::factory()->create([
                'company_id' => $referral->company_id,
                'referral_id' => $referral->id,
                'from_stage' => PipelineStage::Finish1stDoctorMeeting,
                'to_stage' => PipelineStage::CompletePayment,
                'changed_by_user_id' => $agent->id,
            ]);
        }

        $this->assertTrue($this->evaluator()->evaluate(
            [['metric' => 'referrals_completed_count', 'operator' => '>=', 'value' => 1]],
            $agent,
        ));
        // If this becomes true, the other company's deal is being counted.
        $this->assertFalse($this->evaluator()->evaluate(
            [['metric' => 'referrals_completed_count', 'operator' => '>=', 'value' => 2]],
            $agent,
        ));
    }

    public function test_multiple_conditions_use_and_semantics(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agent->id, 'xp_awarded' => 500]);

        $conditions = [
            ['metric' => 'xp_total', 'operator' => '>=', 'value' => 500],
            ['metric' => 'modules_completed_count', 'operator' => '>=', 'value' => 1],
        ];
        // xp_total passes, modules_completed_count doesn't (0 completions) -> overall false
        $this->assertFalse($this->evaluator()->evaluate($conditions, $agent));

        ModuleCompletion::factory()->create(['company_id' => $company->id, 'user_id' => $agent->id]);
        $this->assertTrue($this->evaluator()->evaluate($conditions, $agent));
    }

    public function test_unknown_metric_fails_closed(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $conditions = [['metric' => 'made_up_metric', 'operator' => '>=', 'value' => 0]];
        $this->assertFalse($this->evaluator()->evaluate($conditions, $agent));
    }

    public function test_unknown_operator_fails_closed(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        XpLedger::factory()->create(['company_id' => $company->id, 'user_id' => $agent->id, 'xp_awarded' => 500]);

        $conditions = [['metric' => 'xp_total', 'operator' => '!=', 'value' => 0]];
        $this->assertFalse($this->evaluator()->evaluate($conditions, $agent));
    }
}
