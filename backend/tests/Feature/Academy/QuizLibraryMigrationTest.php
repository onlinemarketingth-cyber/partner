<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-150 / ADR-030 §2.2 — the losslessness fixture.
 *
 * "Each lesson that has questions gets one quiz created for it, named after
 * the lesson, with its questions moved across and `module_lessons.quiz_id`
 * set. No data is lost, no admin has to do anything, and **every existing
 * lesson behaves exactly as it did the moment before**."
 *
 * The last clause is not checkable by reading the migration, so this test
 * runs it for real: `down()` puts the schema back to the pre-ADR-030 shape,
 * a fixture is written in that OLD shape with raw queries, `up()` runs, and
 * then the lesson is taken through the ADR-029 grading endpoint end to end.
 *
 * Raw `DB::table()` throughout for the fixture, because the Eloquent models
 * describe the NEW schema — using them to build the old shape would test
 * nothing.
 */
class QuizLibraryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_08_24_090200_move_lesson_quiz_questions_onto_quizzes.php');
    }

    /**
     * Insert a question + two options in the PRE-ADR-030 shape
     * (`module_lesson_quiz_questions.module_lesson_id`).
     *
     * @return int the question id
     */
    private function legacyQuestion(ModuleLesson $lesson, string $text, int $sortOrder = 0): int
    {
        $questionId = DB::table('module_lesson_quiz_questions')->insertGetId([
            'company_id' => $lesson->company_id,
            'module_lesson_id' => $lesson->id,
            'question_text' => $text,
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('module_lesson_quiz_options')->insert([
            [
                'company_id' => $lesson->company_id,
                'module_lesson_quiz_question_id' => $questionId,
                'option_text' => "right-{$text}",
                'is_correct' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => $lesson->company_id,
                'module_lesson_quiz_question_id' => $questionId,
                'option_text' => "wrong-{$text}",
                'is_correct' => false,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return $questionId;
    }

    private function makeLesson(Company $company, array $attributes = []): ModuleLesson
    {
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        return ModuleLesson::factory()->create(array_merge([
            'company_id' => $company->id,
            'module_id' => $module->id,
        ], $attributes));
    }

    public function test_the_migration_moves_every_question_without_losing_anything(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $withTwo = $this->makeLesson($companyA, ['title' => 'Lesson with two questions']);
        $withOne = $this->makeLesson($companyA, ['title' => 'Lesson with one question']);
        $withNone = $this->makeLesson($companyA, ['title' => 'Lesson with no quiz']);
        $otherCompany = $this->makeLesson($companyB, ['title' => 'Another tenant']);
        $trashed = $this->makeLesson($companyA, ['title' => 'Soft-deleted lesson']);

        $migration = $this->migration();

        // Back to the pre-ADR-030 schema, then build the fixture in it.
        $migration->down();

        $q1 = $this->legacyQuestion($withTwo, 'first', 0);
        $q2 = $this->legacyQuestion($withTwo, 'second', 1);
        $q3 = $this->legacyQuestion($withOne, 'only');
        $q4 = $this->legacyQuestion($otherCompany, 'theirs');
        $q5 = $this->legacyQuestion($trashed, 'still mine');

        $trashed->delete(); // soft delete AFTER authoring, as an admin would

        $migration->up();

        // ---- nothing was lost -------------------------------------------
        $this->assertSame(5, DB::table('module_lesson_quiz_questions')->count());
        $this->assertSame(10, DB::table('module_lesson_quiz_options')->count(), 'options must survive the parent-table rebuild');

        foreach ([$q1, $q2, $q3, $q4, $q5] as $id) {
            $this->assertNotNull(
                DB::table('module_lesson_quiz_questions')->where('id', $id)->first(),
                "question {$id} kept its id"
            );
            $this->assertSame(
                2,
                DB::table('module_lesson_quiz_options')->where('module_lesson_quiz_question_id', $id)->count(),
                "question {$id} kept both options"
            );
        }

        // ---- one quiz per lesson that had questions (§2.2) ---------------
        $this->assertSame(4, DB::table('quizzes')->count(), 'one quiz each for the four lessons that had questions');

        $withTwo->refresh();
        $withOne->refresh();
        $withNone->refresh();
        $otherCompany->refresh();

        $this->assertNotNull($withTwo->quiz_id);
        $this->assertNotNull($withOne->quiz_id);
        $this->assertNotNull($otherCompany->quiz_id);
        $this->assertNull($withNone->quiz_id, 'a lesson with no questions gets no quiz');

        // Not shared — the exclusivity rule holds from the first moment.
        $this->assertNotSame($withTwo->quiz_id, $withOne->quiz_id);

        // "named after the lesson", and stamped with the LESSON's company
        // (BR-6 — never guessed, never global).
        $quiz = DB::table('quizzes')->where('id', $withTwo->quiz_id)->first();
        $this->assertSame('Lesson with two questions', $quiz->title);
        $this->assertSame($companyA->id, (int) $quiz->company_id);

        $foreignQuiz = DB::table('quizzes')->where('id', $otherCompany->quiz_id)->first();
        $this->assertSame($companyB->id, (int) $foreignQuiz->company_id);

        // ---- the questions point at the right quiz ----------------------
        $this->assertSame(
            [$q1, $q2],
            DB::table('module_lesson_quiz_questions')
                ->where('quiz_id', $withTwo->quiz_id)->orderBy('sort_order')->pluck('id')->all()
        );
        $this->assertSame(
            [$q3],
            DB::table('module_lesson_quiz_questions')->where('quiz_id', $withOne->quiz_id)->pluck('id')->all()
        );

        // ---- a soft-deleted lesson keeps its quiz -----------------------
        // Its questions are data too; losing them would mean a restored
        // lesson came back with an empty quiz.
        $trashedRow = DB::table('module_lessons')->where('id', $trashed->id)->first();
        $this->assertNotNull($trashedRow->quiz_id);
        $this->assertSame(
            [$q5],
            DB::table('module_lesson_quiz_questions')->where('quiz_id', $trashedRow->quiz_id)->pluck('id')->all()
        );

        // ---- and the lesson still BEHAVES the same (ADR-029) ------------
        $agent = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $correct = DB::table('module_lesson_quiz_options')
            ->where('module_lesson_quiz_question_id', $q3)->where('is_correct', true)->value('id');

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$withOne->id}/quiz-attempts", [
                'answers' => [$q3 => $correct],
            ])
            ->assertOk()
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.total_questions', 1)
            ->assertJsonPath('data.passed', true);
    }

    public function test_the_migration_is_safe_on_an_empty_database(): void
    {
        // Nothing authored anywhere: every loop iterates zero rows, and the
        // table rebuild still has to leave a usable schema behind.
        $migration = $this->migration();

        $migration->down();
        $migration->up();

        $this->assertSame(0, DB::table('quizzes')->count());
        $this->assertSame(0, DB::table('module_lesson_quiz_questions')->count());

        // The schema is intact and usable afterwards.
        $company = Company::factory()->create();
        $lesson = $this->makeLesson($company);
        $quizId = DB::table('quizzes')->insertGetId([
            'company_id' => $company->id, 'title' => 'after', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('module_lessons')->where('id', $lesson->id)->update(['quiz_id' => $quizId]);
        DB::table('module_lesson_quiz_questions')->insert([
            'company_id' => $company->id, 'quiz_id' => $quizId, 'question_text' => 'q',
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $lesson->fresh()->quizQuestions()->count());
    }

    public function test_rolling_back_returns_the_questions_to_their_lessons(): void
    {
        // Reversible in the direction that matters: everything up() migrated
        // comes back intact. (A question belonging to a quiz no lesson holds
        // is unrepresentable in the old NOT NULL column and is dropped —
        // stated in the migration's own docblock.)
        $company = Company::factory()->create();
        $lesson = $this->makeLesson($company, ['title' => 'Round trip']);

        $migration = $this->migration();
        $migration->down();

        $questionId = $this->legacyQuestion($lesson, 'survivor');

        $migration->up();
        $this->assertNotNull($lesson->fresh()->quiz_id);

        $migration->down();

        $row = DB::table('module_lesson_quiz_questions')->where('id', $questionId)->first();
        $this->assertNotNull($row);
        $this->assertSame($lesson->id, (int) $row->module_lesson_id);
        $this->assertSame('survivor', $row->question_text);
        $this->assertSame(2, DB::table('module_lesson_quiz_options')
            ->where('module_lesson_quiz_question_id', $questionId)->count());
        $this->assertNull(DB::table('module_lessons')->where('id', $lesson->id)->value('quiz_id'));
    }
}
