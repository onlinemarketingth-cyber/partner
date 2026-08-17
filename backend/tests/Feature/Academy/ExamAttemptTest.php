<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamQuestionOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// BR-1: "An agent must pass the Basic certification before gaining
// access to SWS Referral submission and selling features." These tests
// verify the actual gate mechanism (ExamAttemptService -> UserCertification
// -> User::hasPassedCertTier()), not just CRUD around exams.
//
// Academy Sprint 1 (2026-07-21): rewritten from the old placeholder
// shape (client sent a raw `score`) to the real answers-based grading
// flow — every test now builds a real single-question exam and submits
// `answers: [{question_id, option_id}]`.
class ExamAttemptTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One question, two options — enough to deterministically produce
     * score 0 or 100 for pass/fail assertions without needing a bigger
     * question bank per test.
     *
     * @return array{0: Exam, 1: int, 2: int} [$exam, $correctOptionId, $wrongOptionId]
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
        $wrong = ExamQuestionOption::create([
            'company_id' => $company->id,
            'exam_question_id' => $question->id,
            'option_text' => 'A pricing rule',
            'is_correct' => false,
        ]);

        return [$exam, $correct->id, $wrong->id];
    }

    public function test_a_passing_score_creates_a_certification_and_unlocks_the_gate(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);
        [$exam, $correctOptionId] = $this->makeSingleQuestionExam($company, $tier, 70);
        $question = ExamQuestion::where('exam_id', $exam->id)->first();

        $this->assertFalse($agent->fresh()->hasPassedCertTier('basic'));

        $this->actingAs($agent)
            ->postJson('/api/v1/exam-attempts', [
                'exam_id' => $exam->id,
                'answers' => [['question_id' => $question->id, 'option_id' => $correctOptionId]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.score', 100);

        $this->assertTrue($agent->fresh()->hasPassedCertTier('basic'));
    }

    public function test_a_failing_score_does_not_create_a_certification(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);
        [$exam, $correctOptionId, $wrongOptionId] = $this->makeSingleQuestionExam($company, $tier, 70);
        $question = ExamQuestion::where('exam_id', $exam->id)->first();

        $this->actingAs($agent)
            ->postJson('/api/v1/exam-attempts', [
                'exam_id' => $exam->id,
                'answers' => [['question_id' => $question->id, 'option_id' => $wrongOptionId]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.score', 0);

        $this->assertFalse($agent->fresh()->hasPassedCertTier('basic'));
    }

    public function test_an_unanswered_question_counts_as_incorrect(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);
        [$exam] = $this->makeSingleQuestionExam($company, $tier, 70);

        $this->actingAs($agent)
            ->postJson('/api/v1/exam-attempts', ['exam_id' => $exam->id, 'answers' => []])
            ->assertCreated()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.score', 0);
    }

    public function test_client_cannot_self_certify_by_sending_passed_true(): void
    {
        // The client sends "passed": true directly, alongside a wrong
        // answer — the Service must ignore this and compute it
        // server-side from the graded score vs. passing_score, or BR-1
        // is trivially bypassable.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);
        [$exam, $correctOptionId, $wrongOptionId] = $this->makeSingleQuestionExam($company, $tier, 70);
        $question = ExamQuestion::where('exam_id', $exam->id)->first();

        $this->actingAs($agent)->postJson('/api/v1/exam-attempts', [
            'exam_id' => $exam->id,
            'answers' => [['question_id' => $question->id, 'option_id' => $wrongOptionId]],
            'passed' => true,
        ])->assertJsonPath('data.passed', false);

        $this->assertFalse($agent->fresh()->hasPassedCertTier('basic'));
    }

    public function test_retaking_a_passed_exam_does_not_duplicate_the_certification(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);
        [$exam, $correctOptionId] = $this->makeSingleQuestionExam($company, $tier, 70);
        $question = ExamQuestion::where('exam_id', $exam->id)->first();
        $answers = ['answers' => [['question_id' => $question->id, 'option_id' => $correctOptionId]]];

        $this->actingAs($agent)->postJson('/api/v1/exam-attempts', ['exam_id' => $exam->id, ...$answers])->assertCreated();
        $this->actingAs($agent)->postJson('/api/v1/exam-attempts', ['exam_id' => $exam->id, ...$answers])->assertCreated();

        $this->assertSame(1, $agent->certifications()->where('cert_tier_id', $tier->id)->count());
    }

    public function test_cannot_attempt_an_exam_belonging_to_another_company(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $ownCompany->id]);
        $tier = CertTier::factory()->create();
        $foreignExam = Exam::factory()->for($otherCompany)->create(['cert_tier_id' => $tier->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/exam-attempts', ['exam_id' => $foreignExam->id, 'answers' => []])
            ->assertUnprocessable();
    }

    public function test_cannot_attempt_an_exam_with_no_questions(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $exam = Exam::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/exam-attempts', ['exam_id' => $exam->id, 'answers' => []])
            ->assertUnprocessable();
    }

    public function test_an_option_from_a_different_question_is_not_counted_as_correct(): void
    {
        // Defends the firstWhere() guard in ExamAttemptService: a
        // valid-in-this-company option_id that belongs to a DIFFERENT
        // question must not be creditable toward this question.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create(['key' => 'basic']);
        [$examA, $correctOptionIdA] = $this->makeSingleQuestionExam($company, $tier, 70);
        [$examB] = $this->makeSingleQuestionExam($company, $tier, 70);
        $questionB = ExamQuestion::where('exam_id', $examB->id)->first();

        $this->actingAs($agent)
            ->postJson('/api/v1/exam-attempts', [
                'exam_id' => $examB->id,
                'answers' => [['question_id' => $questionB->id, 'option_id' => $correctOptionIdA]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.score', 0);
    }
}
