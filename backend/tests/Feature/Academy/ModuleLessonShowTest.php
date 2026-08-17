<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonQuizOption;
use App\Models\ModuleLessonQuizQuestion;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-167 §3 — GET /module-lessons/{moduleLesson}.
 *
 * The Agent Portal's lesson content moved to its own route, so the lesson
 * has to be able to fetch ITSELF: before this endpoint a deep link or a
 * page refresh on /academy/lessons/:id had no /modules payload to read from
 * and landed on an empty screen.
 *
 * The point of these tests is that the single read respects EXACTLY what
 * the list already respects — not more, not less:
 *
 *   TASK-155      a draft lesson (or one in a draft Section) is 404 to an
 *                 Agent, readable to an Admin who is authoring it.
 *   BR-6 / §5.5   another company's lesson is 404, never 403.
 *   ADR-031       a locked lesson answers with its lock REASON and withholds
 *                 the quiz, exactly as the row in the list does.
 *   TASK-165      `completion_is_automatic` is present, because the lesson
 *                 screen decides whether to render a completion button from
 *                 it and must never re-derive it.
 */
class ModuleLessonShowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{company: Company, agent: User, admin: User, module: Module, lesson: ModuleLesson}
     */
    private function scenario(array $moduleAttributes = [], array $lessonAttributes = []): array
    {
        $company = Company::factory()->create();
        $tier = CertTier::factory()->create();

        $module = Module::factory()->for($company)->create(array_merge([
            'cert_tier_id' => $tier->id,
            'is_published' => true,
        ], $moduleAttributes));

        return [
            'company' => $company,
            'agent' => User::factory()->agent()->create(['company_id' => $company->id]),
            'admin' => User::factory()->companyAdmin()->create(['company_id' => $company->id]),
            'module' => $module,
            'lesson' => ModuleLesson::factory()->create(array_merge([
                'company_id' => $company->id,
                'module_id' => $module->id,
                'content_type' => 'video',
                'source_type' => null,
                'is_published' => true,
                'is_optional' => false,
                'sort_order' => 0,
            ], $lessonAttributes)),
        ];
    }

    public function test_an_agent_reads_a_published_lesson_in_their_own_company(): void
    {
        $s = $this->scenario();

        $this->actingAs($s['agent'])
            ->getJson("/api/v1/module-lessons/{$s['lesson']->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $s['lesson']->id)
            ->assertJsonPath('data.module_id', $s['module']->id)
            ->assertJsonPath('data.title', $s['lesson']->title)
            ->assertJsonPath('data.is_locked', false);
    }

    public function test_the_payload_carries_the_two_fields_the_lesson_screen_decides_from(): void
    {
        // TASK-165 §3.1 / ADR-029 §2.1 — the screen shows or hides the
        // completion button from `completion_is_automatic` and announces the
        // quiz from `quiz_question_count`. Neither may be re-derived client
        // side, so both must actually be on the single read.
        $s = $this->scenario();

        $this->actingAs($s['agent'])
            ->getJson("/api/v1/module-lessons/{$s['lesson']->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'content_type',
                    'source_type',
                    'stream_url',
                    'inline_url',
                    'completion_is_automatic',
                    'quiz_question_count',
                    'is_optional',
                    'is_locked',
                    'lock_reason',
                    'lock_message',
                ],
            ]);
    }

    public function test_another_companys_lesson_is_not_found(): void
    {
        $s = $this->scenario();

        $outsider = User::factory()->agent()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        // 404 via TenantScope, not 403: guessing an id must not confirm that
        // the row exists (CLAUDE.md §5.5).
        $this->actingAs($outsider)
            ->getJson("/api/v1/module-lessons/{$s['lesson']->id}")
            ->assertNotFound();
    }

    public function test_an_unauthenticated_visitor_is_rejected(): void
    {
        $s = $this->scenario();

        $this->getJson("/api/v1/module-lessons/{$s['lesson']->id}")->assertUnauthorized();
    }

    public function test_a_draft_lesson_is_not_found_for_an_agent_but_readable_for_an_admin(): void
    {
        $s = $this->scenario(lessonAttributes: ['is_published' => false]);

        $this->actingAs($s['agent'])
            ->getJson("/api/v1/module-lessons/{$s['lesson']->id}")
            ->assertNotFound();

        // Previewing what you are drafting is the whole reason the Admin
        // exemption exists (LessonAccessGate / ModuleController::show).
        $this->actingAs($s['admin'])
            ->getJson("/api/v1/module-lessons/{$s['lesson']->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $s['lesson']->id);
    }

    public function test_a_published_lesson_inside_a_draft_section_is_not_found_for_an_agent(): void
    {
        // The Section's flag counts as much as the lesson's — an admin who
        // unpublishes a Section means its contents are out of the course.
        $s = $this->scenario(moduleAttributes: ['is_published' => false]);

        $this->actingAs($s['agent'])
            ->getJson("/api/v1/module-lessons/{$s['lesson']->id}")
            ->assertNotFound();

        $this->actingAs($s['admin'])
            ->getJson("/api/v1/module-lessons/{$s['lesson']->id}")
            ->assertOk();
    }

    public function test_a_locked_lesson_answers_with_its_lock_reason(): void
    {
        // ADR-031 §2.2 — lesson 2 of a sequential Section, lesson 1 unfinished.
        $s = $this->scenario(moduleAttributes: ['enforce_sequential' => true]);

        $second = ModuleLesson::factory()->create([
            'company_id' => $s['company']->id,
            'module_id' => $s['module']->id,
            'content_type' => 'video',
            'source_type' => null,
            'is_published' => true,
            'is_optional' => false,
            'sort_order' => 1,
        ]);

        // ADR-031 §4 item 2 — SHOWN and greyed, not hidden: 200 with the
        // reason, so the screen can explain the lock instead of 404-ing on a
        // lesson the learner can see in the list.
        $this->actingAs($s['agent'])
            ->getJson("/api/v1/module-lessons/{$second->id}")
            ->assertOk()
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.lock_reason', 'sequential_previous')
            ->assertJsonPath('data.lock_message', 'ต้องเรียนบทก่อนหน้าให้จบก่อน จึงจะเข้าเรียนบทนี้ได้');

        ModuleCompletion::create([
            'company_id' => $s['company']->id,
            'user_id' => $s['agent']->id,
            'module_lesson_id' => $s['lesson']->id,
            'completed_at' => now(),
        ]);

        $this->actingAs($s['agent'])
            ->getJson("/api/v1/module-lessons/{$second->id}")
            ->assertOk()
            ->assertJsonPath('data.is_locked', false)
            ->assertJsonPath('data.lock_message', null);
    }

    public function test_a_locked_lesson_hands_out_no_quiz_questions(): void
    {
        // ADR-031 §2.2 — the questions are WITHHELD from the payload, not
        // shipped with a "don't render this" flag. The count still travels so
        // the screen can say a quiz exists.
        $s = $this->scenario(moduleAttributes: ['enforce_sequential' => true]);

        $second = ModuleLesson::factory()->create([
            'company_id' => $s['company']->id,
            'module_id' => $s['module']->id,
            'content_type' => 'video',
            'source_type' => null,
            'is_published' => true,
            'is_optional' => false,
            'sort_order' => 1,
        ]);

        $quiz = Quiz::create(['company_id' => $s['company']->id, 'title' => 'q']);
        $question = ModuleLessonQuizQuestion::create([
            'company_id' => $s['company']->id,
            'quiz_id' => $quiz->id,
            'question_text' => 'Q1?',
            'sort_order' => 0,
        ]);
        ModuleLessonQuizOption::create([
            'company_id' => $s['company']->id,
            'module_lesson_quiz_question_id' => $question->id,
            'option_text' => 'A',
            'is_correct' => true,
            'sort_order' => 0,
        ]);
        // quiz_id is not fillable by design (ADR-030 §2.1) — the link moves
        // only through QuizService in production.
        $second->forceFill(['quiz_id' => $quiz->id])->save();

        $data = $this->actingAs($s['agent'])
            ->getJson("/api/v1/module-lessons/{$second->id}")
            ->assertOk()
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.quiz_unlocked', false)
            ->assertJsonPath('data.quiz_question_count', 1)
            ->json('data');

        $this->assertArrayNotHasKey('quiz_questions', $data);
    }
}
