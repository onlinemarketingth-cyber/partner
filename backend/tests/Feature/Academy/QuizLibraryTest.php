<?php

namespace Tests\Feature\Academy;

use App\Models\AuditLog;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonQuizAttempt;
use App\Models\ModuleLessonQuizOption;
use App\Models\ModuleLessonQuizQuestion;
use App\Models\Quiz;
use App\Models\User;
use App\Services\Academy\QuizService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-150 / ADR-030 — the quiz library and its one hard rule:
 *
 *   **one quiz belongs to at most one lesson, forever, until it is
 *   explicitly unlinked** (§1).
 *
 * The section that matters most is "the UNIQUE constraint is the rule": the
 * database refuses a second lesson even when the Service is bypassed
 * entirely, which is what makes the rule true for seeders and console
 * commands as well as for HTTP.
 */
class QuizLibraryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: ModuleLesson} */
    private function makeCompanyAdminAndLesson(): array
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
        ]);

        return [$company, $admin, $lesson];
    }

    private function makeLessonIn(Company $company): ModuleLesson
    {
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        return ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
        ]);
    }

    /** One question with a right and a wrong option, on a LIBRARY quiz. */
    private function stockQuiz(Quiz $quiz, string $text = 'q'): ModuleLessonQuizQuestion
    {
        $question = ModuleLessonQuizQuestion::create([
            'company_id' => $quiz->company_id,
            'quiz_id' => $quiz->id,
            'question_text' => $text,
        ]);

        ModuleLessonQuizOption::create([
            'company_id' => $quiz->company_id,
            'module_lesson_quiz_question_id' => $question->id,
            'option_text' => 'right',
            'is_correct' => true,
        ]);
        ModuleLessonQuizOption::create([
            'company_id' => $quiz->company_id,
            'module_lesson_quiz_question_id' => $question->id,
            'option_text' => 'wrong',
            'is_correct' => false,
        ]);

        return $question;
    }

    // =================================================================
    // §2.1 — THE UNIQUE CONSTRAINT IS THE RULE
    // =================================================================

    public function test_the_database_itself_refuses_to_give_one_quiz_to_a_second_lesson(): void
    {
        [$company, , $lessonA] = $this->makeCompanyAdminAndLesson();
        $lessonB = $this->makeLessonIn($company);
        $quiz = Quiz::factory()->create(['company_id' => $company->id]);

        app(QuizService::class)->attach($lessonA, $quiz);

        /*
         * ADR-030 §2.1 — "Two lessons cannot claim one quiz even under a
         * race, a seeder, or a console command."
         *
         * This is that sentence as a test: a RAW query, with no Form
         * Request, no Policy and no Service anywhere near it — exactly what
         * a seeder or an artisan command would do. If the rule lived only in
         * PHP this line would succeed and the second lesson would silently
         * steal the quiz.
         */
        $threw = false;

        try {
            DB::table('module_lessons')->where('id', $lessonB->id)->update(['quiz_id' => $quiz->id]);
        } catch (QueryException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'module_lessons.quiz_id must be UNIQUE at the database level');
        $this->assertNull($lessonB->fresh()->quiz_id);
        $this->assertSame($quiz->id, $lessonA->fresh()->quiz_id);
    }

    public function test_many_lessons_may_have_no_quiz_at_all(): void
    {
        // The other half of "nullable + UNIQUE": a unique index ignores
        // NULLs, so "no quiz" is not a value two lessons can collide on.
        [$company, , $lessonA] = $this->makeCompanyAdminAndLesson();
        $lessonB = $this->makeLessonIn($company);
        $lessonC = $this->makeLessonIn($company);

        $this->assertNull($lessonA->quiz_id);
        $this->assertNull($lessonB->quiz_id);
        $this->assertNull($lessonC->quiz_id);
        $this->assertSame(3, ModuleLesson::withoutGlobalScopes()->whereNull('quiz_id')->count());
    }

    public function test_the_api_refuses_a_quiz_that_another_lesson_already_holds(): void
    {
        [$company, $admin, $lessonA] = $this->makeCompanyAdminAndLesson();
        $lessonB = $this->makeLessonIn($company);
        $quiz = Quiz::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lessonA->id}/quiz", ['quiz_id' => $quiz->id])
            ->assertOk();

        // §2.1 — the admin gets a 422 with a sentence, not a driver error.
        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lessonB->id}/quiz", ['quiz_id' => $quiz->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quiz_id');

        $this->assertNull($lessonB->fresh()->quiz_id);
    }

    // =================================================================
    // §2.3 — attach / detach round trip
    // =================================================================

    public function test_attach_and_detach_round_trip(): void
    {
        [$company, $admin, $lesson] = $this->makeCompanyAdminAndLesson();
        $quiz = Quiz::factory()->create(['company_id' => $company->id]);
        $this->stockQuiz($quiz, 'authored in the library first');

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/quiz", ['quiz_id' => $quiz->id])
            ->assertOk()
            ->assertJsonPath('data.quiz_id', $quiz->id)
            // The whole point of the library: the questions authored before
            // the lesson existed now belong to it.
            ->assertJsonPath('data.quiz_question_count', 1);

        $this->assertSame($quiz->id, $lesson->fresh()->quiz_id);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/module-lessons/{$lesson->id}/quiz")
            ->assertOk()
            ->assertJsonPath('data.quiz_id', null)
            ->assertJsonPath('data.quiz_question_count', 0);

        $this->assertNull($lesson->fresh()->quiz_id);

        // §2.3 — "Unlinking returns the quiz to the library": intact, with
        // its questions, and attachable again.
        $this->assertNotNull($quiz->fresh());
        $this->assertSame(1, ModuleLessonQuizQuestion::withoutGlobalScopes()->where('quiz_id', $quiz->id)->count());

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/quiz", ['quiz_id' => $quiz->id])
            ->assertOk();
    }

    public function test_attaching_and_detaching_are_audit_logged(): void
    {
        // CLAUDE.md §6 — this can switch a completion gate that feeds the
        // BR-1 certification path on or off.
        [$company, $admin, $lesson] = $this->makeCompanyAdminAndLesson();
        $quiz = Quiz::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->putJson("/api/v1/module-lessons/{$lesson->id}/quiz", ['quiz_id' => $quiz->id])->assertOk();
        $this->actingAs($admin)->deleteJson("/api/v1/module-lessons/{$lesson->id}/quiz")->assertOk();

        $attached = AuditLog::where('action', 'module_lesson.quiz_attached')->firstOrFail();
        $this->assertSame($admin->id, $attached->actor_user_id);
        $this->assertSame($quiz->id, $attached->new_values['quiz_id']);

        $detached = AuditLog::where('action', 'module_lesson.quiz_detached')->firstOrFail();
        $this->assertSame($quiz->id, $detached->old_values['quiz_id']);
        $this->assertNull($detached->new_values['quiz_id']);
    }

    public function test_detaching_leaves_recorded_attempts_alone_and_reopens_completion(): void
    {
        /*
         * ADR-030 §2.3 — "Attempts are not affected —
         * module_lesson_quiz_attempts.module_lesson_id stays pointed at the
         * lesson, because an attempt is a record of A LEARNER DOING A
         * LESSON, not of a quiz in the abstract."
         *
         * And the consequence worth being explicit about: with no quiz
         * there is nothing to pass, so a quiz_blocks_completion lesson
         * becomes completable again (LessonCompletionGate::isQuizSatisfied).
         */
        [$company, $admin, $lesson] = $this->makeCompanyAdminAndLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $quiz = Quiz::factory()->create(['company_id' => $company->id]);
        $question = $this->stockQuiz($quiz);
        $wrong = $question->options()->where('is_correct', false)->firstOrFail();

        app(QuizService::class)->attach($lesson, $quiz);
        $lesson->update(['quiz_blocks_completion' => true]);

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$question->id => $wrong->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.passed', false);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable();

        $this->actingAs($admin)->deleteJson("/api/v1/module-lessons/{$lesson->id}/quiz")->assertOk();

        $attempt = ModuleLessonQuizAttempt::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($lesson->id, $attempt->module_lesson_id, 'the attempt still belongs to the lesson');
        $this->assertFalse($attempt->passed, 'passed stays frozen at attempt time (ADR-029)');

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    // =================================================================
    // §2.4 — a linked quiz cannot be deleted
    // =================================================================

    public function test_a_linked_quiz_cannot_be_deleted(): void
    {
        [$company, $admin, $lesson] = $this->makeCompanyAdminAndLesson();
        $quiz = Quiz::factory()->create(['company_id' => $company->id]);
        $question = $this->stockQuiz($quiz);
        app(QuizService::class)->attach($lesson, $quiz);
        $lesson->update(['quiz_blocks_completion' => true]);

        // §2.4 — 422 and "unlink first", NOT a 403: the admin may delete
        // quizzes, this one just has a lesson depending on it.
        $this->actingAs($admin)
            ->deleteJson("/api/v1/quizzes/{$quiz->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quiz_id');

        // The gate is intact — nothing was silently loosened.
        $this->assertNotNull($quiz->fresh());
        $this->assertSame(1, ModuleLessonQuizQuestion::withoutGlobalScopes()->where('quiz_id', $quiz->id)->count());
        $this->assertNotNull($question->fresh());
        $this->assertSame($quiz->id, $lesson->fresh()->quiz_id);

        // Unlink first, then it deletes.
        $this->actingAs($admin)->deleteJson("/api/v1/module-lessons/{$lesson->id}/quiz")->assertOk();
        $this->actingAs($admin)->deleteJson("/api/v1/quizzes/{$quiz->id}")->assertNoContent();

        $this->assertSoftDeleted('quizzes', ['id' => $quiz->id]);
    }

    // =================================================================
    // §2.5 — the picker only offers what is actually available
    // =================================================================

    public function test_the_available_list_is_unattached_quizzes_plus_the_current_one(): void
    {
        [$company, $admin, $lesson] = $this->makeCompanyAdminAndLesson();
        $otherLesson = $this->makeLessonIn($company);

        $free = Quiz::factory()->create(['company_id' => $company->id, 'title' => 'A free']);
        $mine = Quiz::factory()->create(['company_id' => $company->id, 'title' => 'B mine']);
        $taken = Quiz::factory()->create(['company_id' => $company->id, 'title' => 'C taken']);
        $deleted = Quiz::factory()->create(['company_id' => $company->id, 'title' => 'D deleted']);
        $foreign = Quiz::factory()->create(['company_id' => Company::factory()->create()->id, 'title' => 'E foreign']);

        app(QuizService::class)->attach($lesson, $mine);
        app(QuizService::class)->attach($otherLesson, $taken);
        $deleted->delete();

        $ids = collect(
            $this->actingAs($admin)
                ->getJson("/api/v1/module-lessons/{$lesson->id}/available-quizzes")
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        // §2.5 — unattached in the same company, plus the one currently
        // attached. Nothing else: an offer that then fails is the failure
        // this endpoint exists to prevent.
        $this->assertEqualsCanonicalizing([$free->id, $mine->id], $ids);
        $this->assertNotContains($taken->id, $ids);
        $this->assertNotContains($deleted->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_a_quiz_held_by_a_soft_deleted_lesson_is_not_offered(): void
    {
        /*
         * A soft-deleted lesson still OCCUPIES the quiz_id as far as the
         * UNIQUE index is concerned, so offering its quiz would produce a
         * choice that fails at the database — the §2.5 failure mode with an
         * invisible cause.
         */
        [$company, $admin, $lesson] = $this->makeCompanyAdminAndLesson();
        $doomed = $this->makeLessonIn($company);
        $quiz = Quiz::factory()->create(['company_id' => $company->id]);

        app(QuizService::class)->attach($doomed, $quiz);
        $doomed->delete();

        $ids = collect(
            $this->actingAs($admin)
                ->getJson("/api/v1/module-lessons/{$lesson->id}/available-quizzes")
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertNotContains($quiz->id, $ids);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/quiz", ['quiz_id' => $quiz->id])
            ->assertUnprocessable();
    }

    public function test_the_unattached_filter_lists_library_orphans(): void
    {
        // ADR-030 §3 — "the library will accumulate orphans (authored, never
        // attached). Show them as such."
        [$company, $admin, $lesson] = $this->makeCompanyAdminAndLesson();
        $orphan = Quiz::factory()->create(['company_id' => $company->id]);
        $used = Quiz::factory()->create(['company_id' => $company->id]);
        app(QuizService::class)->attach($lesson, $used);

        $ids = collect(
            $this->actingAs($admin)->getJson('/api/v1/quizzes?unattached=1')->assertOk()->json('data')
        )->pluck('id')->all();

        $this->assertSame([$orphan->id], $ids);

        $all = collect($this->actingAs($admin)->getJson('/api/v1/quizzes')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$orphan->id, $used->id], $all->pluck('id')->all());
        $this->assertTrue($all->firstWhere('id', $used->id)['is_attached']);
        $this->assertFalse($all->firstWhere('id', $orphan->id)['is_attached']);
    }

    // =================================================================
    // The library workflow itself (§1 — "preparation, not reuse")
    // =================================================================

    public function test_a_quiz_authored_in_the_library_grades_once_attached(): void
    {
        [$company, $admin, $lesson] = $this->makeCompanyAdminAndLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // 1. Author it with no lesson in sight — the reason ADR-030 exists.
        $quizId = $this->actingAs($admin)
            ->postJson('/api/v1/quizzes', ['title' => 'Prepared in advance'])
            ->assertCreated()
            ->json('data.id');

        $questionId = $this->actingAs($admin)
            ->postJson("/api/v1/quizzes/{$quizId}/questions", ['question_text' => 'ready before the lesson?'])
            ->assertCreated()
            ->json('data.id');

        $correctId = $this->actingAs($admin)
            ->postJson("/api/v1/module-lesson-quiz-questions/{$questionId}/options", ['option_text' => 'yes', 'is_correct' => true])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin)
            ->postJson("/api/v1/module-lesson-quiz-questions/{$questionId}/options", ['option_text' => 'no'])
            ->assertCreated();

        // 2. Attach it later.
        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/quiz", ['quiz_id' => $quizId])
            ->assertOk();

        // 3. ADR-029 grading works, unchanged, on a quiz that was never
        // typed into the lesson.
        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$questionId => $correctId],
            ])
            ->assertOk()
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.passed', true);
    }

    public function test_an_admin_can_rename_a_quiz_and_read_it_back_with_its_questions(): void
    {
        [$company, $admin] = $this->makeCompanyAdminAndLesson();
        $quiz = Quiz::factory()->create(['company_id' => $company->id, 'title' => 'before']);
        $this->stockQuiz($quiz, 'q1');

        $this->actingAs($admin)
            ->putJson("/api/v1/quizzes/{$quiz->id}", ['title' => 'after'])
            ->assertOk()
            ->assertJsonPath('data.title', 'after')
            ->assertJsonPath('data.question_count', 1);

        $this->actingAs($admin)
            ->getJson("/api/v1/quizzes/{$quiz->id}")
            ->assertOk()
            ->assertJsonPath('data.questions.0.question_text', 'q1')
            // The library is admin-only, so the answer key is visible here
            // exactly as it is in the lesson authoring view.
            ->assertJsonPath('data.questions.0.options.0.is_correct', true);
    }

    // =================================================================
    // BR-6 / §5 rule 5 — tenant isolation
    // =================================================================

    public function test_a_lesson_cannot_attach_another_companys_quiz(): void
    {
        [, $admin, $lesson] = $this->makeCompanyAdminAndLesson();
        $foreignQuiz = Quiz::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/quiz", ['quiz_id' => $foreignQuiz->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quiz_id');

        $this->assertNull($lesson->fresh()->quiz_id);
    }

    public function test_even_a_super_admin_cannot_attach_a_quiz_from_a_different_company(): void
    {
        /*
         * BR-6 with the ONE actor TenantScope does not narrow. A Super Admin
         * can legitimately see both companies, which is precisely why the
         * "same tenant as the lesson" check cannot be left to the scope: it
         * is a rule about the DATA, not about the viewer.
         */
        [, , $lesson] = $this->makeCompanyAdminAndLesson();
        $superAdmin = User::factory()->superAdmin()->create();
        $foreignQuiz = Quiz::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/quiz", ['quiz_id' => $foreignQuiz->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quiz_id');

        $this->assertNull($lesson->fresh()->quiz_id);
    }

    public function test_another_companys_admin_cannot_read_or_delete_a_quiz(): void
    {
        [$company] = $this->makeCompanyAdminAndLesson();
        $quiz = Quiz::factory()->create(['company_id' => $company->id]);
        $outsider = User::factory()->companyAdmin()->create(['company_id' => Company::factory()->create()->id]);

        // TenantScope removes the row at route-model binding, so this is a
        // 404 before the Policy runs (§5 rule 5: 403 OR 404).
        $this->actingAs($outsider)->getJson("/api/v1/quizzes/{$quiz->id}")->assertNotFound();
        $this->actingAs($outsider)->putJson("/api/v1/quizzes/{$quiz->id}", ['title' => 'mine now'])->assertNotFound();
        $this->actingAs($outsider)->deleteJson("/api/v1/quizzes/{$quiz->id}")->assertNotFound();
    }

    public function test_the_library_index_never_leaks_another_companys_quizzes(): void
    {
        [$company, $admin] = $this->makeCompanyAdminAndLesson();
        $mine = Quiz::factory()->create(['company_id' => $company->id]);
        $theirs = Quiz::factory()->create(['company_id' => Company::factory()->create()->id]);

        $ids = collect($this->actingAs($admin)->getJson('/api/v1/quizzes')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_an_agent_cannot_touch_the_library(): void
    {
        /*
         * QuizPolicy is admin-only in EVERY verb, deliberately narrower than
         * ModulePolicy: the library carries `is_correct` on every option, so
         * handing an Agent the list would hand them every answer key in the
         * company (ADR-029 §2.7 masks it only on the learner-facing embed).
         */
        [$company, , $lesson] = $this->makeCompanyAdminAndLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $quiz = Quiz::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson('/api/v1/quizzes')->assertForbidden();
        $this->actingAs($agent)->getJson("/api/v1/quizzes/{$quiz->id}")->assertForbidden();
        $this->actingAs($agent)->postJson('/api/v1/quizzes', ['title' => 'x'])->assertForbidden();
        $this->actingAs($agent)->putJson("/api/v1/quizzes/{$quiz->id}", ['title' => 'x'])->assertForbidden();
        $this->actingAs($agent)->deleteJson("/api/v1/quizzes/{$quiz->id}")->assertForbidden();
        $this->actingAs($agent)->getJson("/api/v1/quizzes/{$quiz->id}/questions")->assertForbidden();
        $this->actingAs($agent)->postJson("/api/v1/quizzes/{$quiz->id}/questions", ['question_text' => 'x'])->assertForbidden();

        // ...and cannot rewire a lesson either.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/quiz", ['quiz_id' => $quiz->id])
            ->assertForbidden();
        $this->actingAs($agent)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/available-quizzes")
            ->assertForbidden();
        $this->actingAs($agent)
            ->deleteJson("/api/v1/module-lessons/{$lesson->id}/quiz")
            ->assertForbidden();
    }

    public function test_the_library_requires_authentication(): void
    {
        $this->getJson('/api/v1/quizzes')->assertUnauthorized();
    }
}
