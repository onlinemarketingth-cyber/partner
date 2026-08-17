<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonQuizOption;
use App\Models\ModuleLessonQuizQuestion;
use App\Models\User;
use App\Services\Academy\QuizService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-009 Sprint B — formative lesson-quiz authoring: CRUD, tenant
// isolation, and the is_correct masking rule, mirroring
// ExamQuestionTest exactly (same authoring pattern, different table).
// Lesson quizzes never gate BR-1 — they are formative-only, separate
// from the Exam engine.
class ModuleLessonQuizTest extends TestCase
{
    use RefreshDatabase;

    private function makeQuizLesson(Company $company, CertTier $tier): ModuleLesson
    {
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        return ModuleLesson::factory()->for($company)->create([
            'module_id' => $module->id,
            'content_type' => 'quiz',
            'source_type' => null,
            'content_ref' => null,
        ]);
    }

    /**
     * ADR-030 §2.1 — a question belongs to a QUIZ, and the lesson gets one
     * on first use through the same Service the authoring endpoint calls
     * (§3). Written as a helper so these ADR-009 tests keep describing the
     * production path rather than a hand-assembled link.
     */
    private function makeQuestion(ModuleLesson $lesson, string $text = 'q'): ModuleLessonQuizQuestion
    {
        $quiz = app(QuizService::class)->ensureForLesson($lesson);

        return ModuleLessonQuizQuestion::create([
            'company_id' => $lesson->company_id,
            'quiz_id' => $quiz->id,
            'question_text' => $text,
        ]);
    }

    public function test_company_admin_can_author_a_quiz_question_with_options(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $lesson = $this->makeQuizLesson($company, $tier);

        $questionResponse = $this->actingAs($admin)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-questions", ['question_text' => 'What year was Thai Life founded?'])
            ->assertCreated()
            ->json('data');

        $this->actingAs($admin)
            ->postJson("/api/v1/module-lesson-quiz-questions/{$questionResponse['id']}/options", ['option_text' => '1942', 'is_correct' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_correct', true);

        /*
         * ADR-030 §3 — the lesson had no quiz, so posting a question created
         * and attached one ("create a new quiz right here" stays the default
         * path; the admin never has to visit the library).
         */
        $lesson->refresh();
        $this->assertNotNull($lesson->quiz_id);
        $this->assertSame($lesson->title, $lesson->quiz->title, 'the auto-created quiz is named after its lesson');

        $this->assertSame(1, ModuleLessonQuizQuestion::where('quiz_id', $lesson->quiz_id)->count());
        $this->assertSame(1, ModuleLessonQuizOption::where('module_lesson_quiz_question_id', $questionResponse['id'])->count());
    }

    public function test_agent_cannot_author_quiz_questions(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $lesson = $this->makeQuizLesson($company, $tier);

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-questions", ['question_text' => 'x'])
            ->assertForbidden();
    }

    public function test_company_admin_cannot_author_quiz_questions_on_another_companys_lesson(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $tier = CertTier::factory()->create();
        $foreignLesson = $this->makeQuizLesson($otherCompany, $tier);

        // TenantScope filters the {moduleLesson} route-model-binding query
        // itself (Company Admin is scoped to their own company_id), so a
        // foreign company's lesson 404s before
        // StoreModuleLessonQuizQuestionRequest::authorize() is ever
        // reached — same convention as every other cross-tenant lookup
        // in this app (Section 5 rule 5: 403 OR 404 is acceptable).
        $this->actingAs($admin)
            ->postJson("/api/v1/module-lessons/{$foreignLesson->id}/quiz-questions", ['question_text' => 'x'])
            ->assertNotFound();
    }

    public function test_marking_a_second_option_correct_unmarks_the_first(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $lesson = $this->makeQuizLesson($company, $tier);
        $question = $this->makeQuestion($lesson);

        $optionA = $this->actingAs($admin)
            ->postJson("/api/v1/module-lesson-quiz-questions/{$question->id}/options", ['option_text' => 'A', 'is_correct' => true])
            ->json('data');

        $this->actingAs($admin)
            ->postJson("/api/v1/module-lesson-quiz-questions/{$question->id}/options", ['option_text' => 'B', 'is_correct' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_correct', true);

        $this->assertFalse(ModuleLessonQuizOption::find($optionA['id'])->is_correct);
    }

    public function test_agent_never_sees_is_correct_but_admin_does(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $lesson = $this->makeQuizLesson($company, $tier);
        $question = $this->makeQuestion($lesson);
        ModuleLessonQuizOption::create(['company_id' => $company->id, 'module_lesson_quiz_question_id' => $question->id, 'option_text' => 'A', 'is_correct' => true]);
        ModuleLessonQuizOption::create(['company_id' => $company->id, 'module_lesson_quiz_question_id' => $question->id, 'option_text' => 'B', 'is_correct' => false]);

        // Lesson quizzes are embedded on the parent Module (Section)
        // response, not exposed via a standalone GET, mirroring how
        // ExamQuestion is embedded on GET /exams/{exam}.
        $agentResponse = $this->actingAs($agent)->getJson("/api/v1/modules/{$lesson->module_id}")->assertOk()->json('data');
        $agentLesson = collect($agentResponse['lessons'])->firstWhere('id', $lesson->id);
        foreach ($agentLesson['quiz_questions'][0]['options'] as $option) {
            $this->assertNull($option['is_correct']);
        }
        $this->assertCount(2, $agentLesson['quiz_questions'][0]['options']);

        $adminResponse = $this->actingAs($admin)->getJson("/api/v1/modules/{$lesson->module_id}")->assertOk()->json('data');
        $adminLesson = collect($adminResponse['lessons'])->firstWhere('id', $lesson->id);
        $this->assertTrue(collect($adminLesson['quiz_questions'][0]['options'])->firstWhere('option_text', 'A')['is_correct']);
    }

    public function test_agent_cannot_use_the_authoring_index_endpoint(): void
    {
        // Regression guard, same rule as ExamQuestionController: GET
        // /module-lessons/{lesson}/quiz-questions always includes
        // is_correct, so it must be gated to `update` (admin-only).
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $lesson = $this->makeQuizLesson($company, $tier);

        $this->actingAs($agent)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/quiz-questions")
            ->assertForbidden();
    }

    public function test_deleting_a_quiz_question_deletes_its_options(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $lesson = $this->makeQuizLesson($company, $tier);
        $question = $this->makeQuestion($lesson);
        $option = ModuleLessonQuizOption::create(['company_id' => $company->id, 'module_lesson_quiz_question_id' => $question->id, 'option_text' => 'A', 'is_correct' => true]);

        $this->actingAs($admin)->deleteJson("/api/v1/module-lesson-quiz-questions/{$question->id}")->assertNoContent();

        $this->assertModelMissing($question);
        $this->assertModelMissing($option);
    }
}
