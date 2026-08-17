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

// Academy Sprint 1 (2026-07-21) — question bank authoring: CRUD, tenant
// isolation, and the is_correct masking rule (Agent must never see the
// answer key, even embedded inside GET /exams/{exam}).
class ExamQuestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_author_a_question_with_options(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $exam = Exam::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $questionResponse = $this->actingAs($admin)
            ->postJson("/api/v1/exams/{$exam->id}/questions", ['question_text' => 'What year was Thai Life founded?'])
            ->assertCreated()
            ->json('data');

        $this->actingAs($admin)
            ->postJson("/api/v1/exam-questions/{$questionResponse['id']}/options", ['option_text' => '1942', 'is_correct' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_correct', true);

        $this->assertSame(1, ExamQuestion::where('exam_id', $exam->id)->count());
        $this->assertSame(1, ExamQuestionOption::where('exam_question_id', $questionResponse['id'])->count());
    }

    public function test_agent_cannot_author_questions(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $exam = Exam::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $this->actingAs($agent)
            ->postJson("/api/v1/exams/{$exam->id}/questions", ['question_text' => 'x'])
            ->assertForbidden();
    }

    public function test_company_admin_cannot_author_questions_on_another_companys_exam(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $tier = CertTier::factory()->create();
        $foreignExam = Exam::factory()->for($otherCompany)->create(['cert_tier_id' => $tier->id]);

        // TenantScope filters the {exam} route-model-binding query itself
        // (Company Admin is scoped to their own company_id), so a foreign
        // company's exam 404s before StoreExamQuestionRequest::authorize()
        // is ever reached — same convention as every other cross-tenant
        // lookup in this app (Section 5 rule 5: 403 OR 404 is acceptable).
        $this->actingAs($admin)
            ->postJson("/api/v1/exams/{$foreignExam->id}/questions", ['question_text' => 'x'])
            ->assertNotFound();
    }

    public function test_marking_a_second_option_correct_unmarks_the_first(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $exam = Exam::factory()->for($company)->create(['cert_tier_id' => $tier->id]);
        $question = ExamQuestion::create(['company_id' => $company->id, 'exam_id' => $exam->id, 'question_text' => 'q']);

        $optionA = $this->actingAs($admin)
            ->postJson("/api/v1/exam-questions/{$question->id}/options", ['option_text' => 'A', 'is_correct' => true])
            ->json('data');

        $this->actingAs($admin)
            ->postJson("/api/v1/exam-questions/{$question->id}/options", ['option_text' => 'B', 'is_correct' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_correct', true);

        $this->assertFalse(ExamQuestionOption::find($optionA['id'])->is_correct);
    }

    public function test_agent_never_sees_is_correct_but_admin_does(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $exam = Exam::factory()->for($company)->create(['cert_tier_id' => $tier->id]);
        $question = ExamQuestion::create(['company_id' => $company->id, 'exam_id' => $exam->id, 'question_text' => 'q']);
        ExamQuestionOption::create(['company_id' => $company->id, 'exam_question_id' => $question->id, 'option_text' => 'A', 'is_correct' => true]);
        ExamQuestionOption::create(['company_id' => $company->id, 'exam_question_id' => $question->id, 'option_text' => 'B', 'is_correct' => false]);

        $agentResponse = $this->actingAs($agent)->getJson("/api/v1/exams/{$exam->id}")->assertOk()->json('data');
        foreach ($agentResponse['questions'][0]['options'] as $option) {
            $this->assertNull($option['is_correct']);
        }
        // Agent must still be able to see the option TEXT to take the exam.
        $this->assertCount(2, $agentResponse['questions'][0]['options']);

        $adminResponse = $this->actingAs($admin)->getJson("/api/v1/exams/{$exam->id}")->assertOk()->json('data');
        $this->assertTrue(collect($adminResponse['questions'][0]['options'])->firstWhere('option_text', 'A')['is_correct']);
    }

    public function test_agent_cannot_use_the_authoring_index_endpoint(): void
    {
        // Regression test: GET /exams/{exam}/questions always includes
        // is_correct (ExamQuestionResource), so it must be gated to
        // `update` (admin-only), not `view` (which Agents also have) —
        // caught during Sprint 1 review before this ever shipped.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $exam = Exam::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $this->actingAs($agent)
            ->getJson("/api/v1/exams/{$exam->id}/questions")
            ->assertForbidden();
    }

    public function test_deleting_a_question_deletes_its_options(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $exam = Exam::factory()->for($company)->create(['cert_tier_id' => $tier->id]);
        $question = ExamQuestion::create(['company_id' => $company->id, 'exam_id' => $exam->id, 'question_text' => 'q']);
        $option = ExamQuestionOption::create(['company_id' => $company->id, 'exam_question_id' => $question->id, 'option_text' => 'A', 'is_correct' => true]);

        $this->actingAs($admin)->deleteJson("/api/v1/exam-questions/{$question->id}")->assertNoContent();

        $this->assertModelMissing($question);
        $this->assertModelMissing($option);
    }
}
