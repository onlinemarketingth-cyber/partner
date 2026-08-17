<?php

namespace Tests\Feature\Gamification;

use App\Enums\GamificationSourceType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\Company;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamQuestionOption;
use App\Models\GamificationRule;
use App\Models\ModuleLesson;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Models\XpLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// BR-5 — the 4 real trigger points (ModuleCompletionService,
// ExamAttemptService, ReferralService, PipelineService) actually award
// XP via GamificationService, using the platform-default
// gamification_rules seeded by GamificationSeeder. Special attention to
// the two farming-prevention gates and the "credit goes to the
// resolved agent, never the actor" rule.
class XpAwardingTest extends TestCase
{
    use RefreshDatabase;

    private function seedDefaultRule(GamificationSourceType $sourceType, int $xpValue): GamificationRule
    {
        return GamificationRule::create([
            'company_id' => null,
            'source_type' => $sourceType,
            'xp_value' => $xpValue,
            'is_active' => true,
        ]);
    }

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);

        return $tier;
    }

    /**
     * Academy Sprint 1 (2026-07-21) rewrote exam-attempts to grade real
     * answers server-side instead of accepting a client-supplied score —
     * mirrors ExamAttemptTest::makeSingleQuestionExam() exactly.
     *
     * @return array{0: Exam, 1: int, 2: int} [$exam, $questionId, $correctOptionId]
     */
    private function makeSingleQuestionExam(Company $company, CertTier $tier, int $passingScore): array
    {
        $exam = Exam::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'passing_score' => $passingScore]);
        $question = ExamQuestion::create([
            'company_id' => $company->id,
            'exam_id' => $exam->id,
            'question_text' => 'What is BR-1?',
        ]);
        $correct = ExamQuestionOption::create([
            'company_id' => $company->id,
            'exam_question_id' => $question->id,
            'option_text' => 'The Basic-cert access gate',
            'is_correct' => true,
        ]);
        ExamQuestionOption::create([
            'company_id' => $company->id,
            'exam_question_id' => $question->id,
            'option_text' => 'A pricing rule',
            'is_correct' => false,
        ]);

        return [$exam, $question->id, $correct->id];
    }

    public function test_completing_a_module_awards_xp_once(): void
    {
        $this->seedDefaultRule(GamificationSourceType::ModuleCompleted, 10);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $lesson = ModuleLesson::factory()->for($company)->create();

        $this->actingAs($agent)->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])->assertCreated();

        $this->assertSame(10, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));

        // Repeating the same completion must NOT award XP a second time
        // (ModuleCompletion is naturally idempotent — firstOrCreate on
        // user_id+module_lesson_id). The response is 200, not 201, here:
        // Laravel's ResourceResponse sets 201 only when the underlying
        // model's wasRecentlyCreated is true — this repeat call resolves
        // to the same, already-existing row, so 200 is the correct,
        // expected status for a no-op idempotent repeat.
        $this->actingAs($agent)->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])->assertOk();

        $this->assertSame(10, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));
    }

    public function test_passing_an_exam_awards_xp_once(): void
    {
        $this->seedDefaultRule(GamificationSourceType::ExamPassed, 50);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);
        [$exam, $questionId, $correctOptionId] = $this->makeSingleQuestionExam($company, $tier, 70);

        $this->actingAs($agent)->postJson('/api/v1/exam-attempts', [
            'exam_id' => $exam->id,
            'answers' => [['question_id' => $questionId, 'option_id' => $correctOptionId]],
        ])->assertCreated();

        $this->assertSame(50, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));
    }

    public function test_retaking_and_repassing_an_already_passed_exam_does_not_award_xp_again(): void
    {
        // This is the farming-prevention test: exam_attempts has no
        // uniqueness constraint (retaking is explicitly allowed), so
        // naively awarding XP off every passing attempt would let an
        // agent farm XP by retaking the same exam repeatedly. XP must
        // be gated on the UserCertification's wasRecentlyCreated flag,
        // not the ExamAttempt's creation.
        $this->seedDefaultRule(GamificationSourceType::ExamPassed, 50);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);
        [$exam, $questionId, $correctOptionId] = $this->makeSingleQuestionExam($company, $tier, 70);
        $answers = ['answers' => [['question_id' => $questionId, 'option_id' => $correctOptionId]]];

        $this->actingAs($agent)->postJson('/api/v1/exam-attempts', ['exam_id' => $exam->id, ...$answers])->assertCreated();
        $this->actingAs($agent)->postJson('/api/v1/exam-attempts', ['exam_id' => $exam->id, ...$answers])->assertCreated();
        $this->actingAs($agent)->postJson('/api/v1/exam-attempts', ['exam_id' => $exam->id, ...$answers])->assertCreated();

        $this->assertSame(50, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));
        $this->assertSame(1, XpLedger::where('user_id', $agent->id)->where('source_type', GamificationSourceType::ExamPassed)->count());
    }

    public function test_submitting_a_referral_awards_xp_to_the_referring_agent_not_the_admin_actor(): void
    {
        $this->seedDefaultRule(GamificationSourceType::ReferralSubmitted, 20);
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/referrals', [
            'client_id' => $client->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay()->toDateTimeString(),
            'agent_id' => $agent->id,
        ])->assertCreated();

        $this->assertSame(20, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));
        $this->assertSame(0, XpLedger::where('user_id', $admin->id)->sum('xp_awarded'));
    }

    public function test_advancing_the_pipeline_awards_stage_xp_and_a_payment_bonus_to_the_agent(): void
    {
        $this->seedDefaultRule(GamificationSourceType::PipelineStageAdvanced, 5);
        $this->seedDefaultRule(GamificationSourceType::PaymentComplete, 100);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        // 1st advance: Complete Registered -> Waiting Appointment (stage XP only)
        $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
        $this->assertSame(5, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));

        // 2nd advance: -> Finish 1st Doctor Meeting (stage XP only)
        $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
        $this->assertSame(10, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));

        // 3rd advance: -> Complete Payment (stage XP + payment bonus)
        $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
        $this->assertSame(115, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));
    }

    public function test_pipeline_advance_credited_to_the_referrals_agent_even_when_a_different_actor_advances_it(): void
    {
        $this->seedDefaultRule(GamificationSourceType::PipelineStageAdvanced, 5);
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);

        $this->actingAs($admin)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();

        $this->assertSame(5, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));
        $this->assertSame(0, XpLedger::where('user_id', $admin->id)->sum('xp_awarded'));
    }

    public function test_no_xp_awarded_and_no_error_when_no_gamification_rule_is_configured(): void
    {
        // Deliberately no GamificationRule seeded — GamificationService
        // must log a warning and return null, never throw or block the
        // triggering action, same non-blocking design as CommissionService.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $lesson = ModuleLesson::factory()->for($company)->create();

        $this->actingAs($agent)->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])->assertCreated();

        $this->assertDatabaseCount('xp_ledger', 0);
    }

    public function test_a_company_specific_rule_overrides_the_platform_default(): void
    {
        $this->seedDefaultRule(GamificationSourceType::ModuleCompleted, 10);
        $company = Company::factory()->create();
        GamificationRule::create([
            'company_id' => $company->id,
            'source_type' => GamificationSourceType::ModuleCompleted,
            'xp_value' => 999,
            'is_active' => true,
        ]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $lesson = ModuleLesson::factory()->for($company)->create();

        $this->actingAs($agent)->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])->assertCreated();

        $this->assertSame(999, XpLedger::where('user_id', $agent->id)->sum('xp_awarded'));
    }
}
