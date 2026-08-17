<?php

namespace Tests\Feature\Academy;

use App\Enums\GamificationSourceType;
use App\Models\AcademyCompletionSetting;
use App\Models\AuditLog;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\GamificationRule;
use App\Models\Module;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonQuizAttempt;
use App\Models\ModuleLessonQuizOption;
use App\Models\ModuleLessonQuizQuestion;
use App\Models\User;
use App\Models\XpLedger;
use App\Services\Academy\QuizService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-149 / ADR-029 — the graded end-of-lesson quiz.
 *
 * The two non-negotiables have their own sections at the bottom:
 * grandfathering (§3 — an existing completion is never re-evaluated) and
 * "passing a quiz awards NO XP" (§4 item 1, unresolved).
 */
class LessonQuizAttemptTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A lesson whose CONTENT gate is already satisfied, so these tests
     * exercise the quiz and nothing else.
     *
     * source_type is null (an external URL, the factory default), which
     * LessonCompletionGate treats as "not verifiable → fall back to the
     * button" (ADR-028 §2.3). The locked case has its own helper below.
     *
     * @return array{0: Company, 1: User, 2: ModuleLesson}
     */
    private function makeLesson(array $attributes = []): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $lesson = ModuleLesson::factory()->create(array_merge([
            'company_id' => $company->id,
            'module_id' => $module->id,
        ], $attributes));

        return [$company, $agent, $lesson];
    }

    /**
     * An UPLOADED video lesson with a known duration and no progress row —
     * i.e. the ADR-028 content gate is CLOSED, so ADR-029 §2.2 says the
     * quiz is locked.
     *
     * @return array{0: Company, 1: User, 2: ModuleLesson}
     */
    private function makeGatedVideoLesson(): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $path = "academy-lessons/{$company->id}/1/".Str::uuid()->toString().'.mp4';
        Storage::disk('local')->put($path, 'fake bytes');

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'video',
            'source_type' => 'upload',
            'content_ref' => $path,
            'duration_seconds' => 600,
        ]);

        return [$company, $agent, $lesson];
    }

    /**
     * One question with a correct and an incorrect option.
     *
     * @return array{question: ModuleLessonQuizQuestion, correct: ModuleLessonQuizOption, wrong: ModuleLessonQuizOption}
     */
    private function makeQuestion(ModuleLesson $lesson, string $text = 'q'): array
    {
        /*
         * ADR-030 §2.1 — questions hang off a QUIZ now, so the lesson needs
         * one. Created through the same Service the authoring endpoint uses
         * (§3 — "create a new quiz right here" is still the default path),
         * so this helper exercises the real link rather than a hand-built
         * one, and every ADR-029 assertion below still describes production
         * behaviour.
         */
        $quiz = app(QuizService::class)->ensureForLesson($lesson);

        $question = ModuleLessonQuizQuestion::create([
            'company_id' => $lesson->company_id,
            'quiz_id' => $quiz->id,
            'question_text' => $text,
            // Explicit and increasing, so `results` comes back in a
            // deterministic order (quizQuestions() orders by sort_order, and
            // ties would otherwise be resolved by the database).
            'sort_order' => ModuleLessonQuizQuestion::withoutGlobalScopes()
                ->where('quiz_id', $quiz->id)
                ->count(),
        ]);

        $correct = ModuleLessonQuizOption::create([
            'company_id' => $lesson->company_id,
            'module_lesson_quiz_question_id' => $question->id,
            'option_text' => 'right',
            'is_correct' => true,
        ]);

        $wrong = ModuleLessonQuizOption::create([
            'company_id' => $lesson->company_id,
            'module_lesson_quiz_question_id' => $question->id,
            'option_text' => 'wrong',
            'is_correct' => false,
        ]);

        return ['question' => $question, 'correct' => $correct, 'wrong' => $wrong];
    }

    private function seedModuleCompletedXpRule(int $xpValue = 10): void
    {
        GamificationRule::create([
            'company_id' => null,
            'source_type' => GamificationSourceType::ModuleCompleted,
            'xp_value' => $xpValue,
            'is_active' => true,
        ]);
    }

    // =================================================================
    // Grading is server-side (ADR-029 §2.3)
    // =================================================================

    public function test_the_server_grades_the_submission_and_counts_only_correct_answers(): void
    {
        [, $agent, $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');
        $q2 = $this->makeQuestion($lesson, 'q2');

        $response = $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [
                    $q1['question']->id => $q1['correct']->id,
                    $q2['question']->id => $q2['wrong']->id,
                ],
            ])
            ->assertOk();

        // score is a COUNT of correct answers, not a percent.
        $response->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.total_questions', 2)
            // 1/2 = 50% < the 80% default → not passed.
            ->assertJsonPath('data.passed', false);

        $attempt = ModuleLessonQuizAttempt::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(1, $attempt->score);
        $this->assertFalse($attempt->passed);
    }

    public function test_an_unanswered_question_counts_as_incorrect(): void
    {
        [, $agent, $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');
        $this->makeQuestion($lesson, 'q2');

        // Same convention as ExamAttemptService: omitted → wrong.
        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['correct']->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.total_questions', 2)
            ->assertJsonPath('data.results.1.answered', false)
            ->assertJsonPath('data.results.1.is_correct', false);
    }

    public function test_an_option_lifted_from_another_question_is_not_counted_correct(): void
    {
        [, $agent, $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');
        $q2 = $this->makeQuestion($lesson, 'q2');

        // q2's correct option submitted as the answer to q1. It passes the
        // Form Request's tenant-scoped `exists` rule, so only the Service's
        // "does this option belong to THIS question" check can catch it.
        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q2['correct']->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.results.0.is_correct', false);
    }

    public function test_answers_referencing_a_question_outside_this_lesson_are_rejected(): void
    {
        [$company, $agent, $lesson] = $this->makeLesson();
        $this->makeQuestion($lesson, 'q1');

        $otherLesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $lesson->module_id,
        ]);
        $foreign = $this->makeQuestion($otherLesson, 'elsewhere');

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$foreign['question']->id => $foreign['correct']->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answers');

        $this->assertSame(0, ModuleLessonQuizAttempt::withoutGlobalScopes()->count());
    }

    public function test_a_lesson_with_no_questions_cannot_be_attempted(): void
    {
        [$company, $agent, $lesson] = $this->makeLesson();

        // A real, same-company option — so this fails on the KEY check (the
        // lesson has no question with that id), not on a dangling
        // reference. The Service carries the same guard as
        // defence-in-depth; the Request makes it unreachable from here.
        $elsewhere = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $lesson->module_id,
        ]);
        $q = $this->makeQuestion($elsewhere, 'q1');

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q['question']->id => $q['correct']->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answers');

        $this->assertSame(0, ModuleLessonQuizAttempt::withoutGlobalScopes()->count());
    }

    // =================================================================
    // The pass mark — boundary + resolution chain (ADR-029 §2.4, BR-7)
    // =================================================================

    public function test_the_pass_mark_is_exact_at_the_boundary(): void
    {
        [, $agent, $lesson] = $this->makeLesson();

        // 5 questions, default 80% → exactly 4 correct passes, 3 does not.
        $questions = collect(range(1, 5))->map(fn ($i) => $this->makeQuestion($lesson, "q{$i}"));
        $url = "/api/v1/module-lessons/{$lesson->id}/quiz-attempts";

        $threeRight = $questions->mapWithKeys(fn ($q, $i) => [
            $q['question']->id => ($i < 3 ? $q['correct']->id : $q['wrong']->id),
        ])->all();

        $this->actingAs($agent)->postJson($url, ['answers' => $threeRight])
            ->assertOk()
            ->assertJsonPath('data.score', 3)
            ->assertJsonPath('data.passed', false);

        $fourRight = $questions->mapWithKeys(fn ($q, $i) => [
            $q['question']->id => ($i < 4 ? $q['correct']->id : $q['wrong']->id),
        ])->all();

        $this->actingAs($agent)->postJson($url, ['answers' => $fourRight])
            ->assertOk()
            ->assertJsonPath('data.score', 4)
            ->assertJsonPath('data.passed', true);
    }

    public function test_a_company_can_lower_its_own_quiz_pass_mark(): void
    {
        [$company, $agent, $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');
        $q2 = $this->makeQuestion($lesson, 'q2');

        // BR-7 — the pass mark is config, never a constant.
        AcademyCompletionSetting::create([
            'company_id' => $company->id,
            'video_watch_percent' => 80,
            'pdf_read_percent' => 100,
            'quiz_pass_percent' => 50,
        ]);

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [
                    $q1['question']->id => $q1['correct']->id,
                    $q2['question']->id => $q2['wrong']->id,
                ],
            ])
            ->assertOk()
            // 1/2 = 50%, which fails the platform default of 80 and passes
            // this company's 50.
            ->assertJsonPath('data.passed', true);
    }

    public function test_a_per_lesson_pass_mark_beats_the_company_setting(): void
    {
        [$company, $agent, $lesson] = $this->makeLesson();

        AcademyCompletionSetting::create([
            'company_id' => $company->id,
            'video_watch_percent' => 80,
            'pdf_read_percent' => 100,
            'quiz_pass_percent' => 50,
        ]);

        // ADR-029 §2.4 — most specific wins. This lesson demands 100%.
        $lesson->update(['quiz_pass_percent' => 100]);

        $q1 = $this->makeQuestion($lesson, 'q1');
        $q2 = $this->makeQuestion($lesson, 'q2');
        $url = "/api/v1/module-lessons/{$lesson->id}/quiz-attempts";

        $this->actingAs($agent)->postJson($url, ['answers' => [
            $q1['question']->id => $q1['correct']->id,
            $q2['question']->id => $q2['wrong']->id,
        ]])->assertOk()->assertJsonPath('data.passed', false);

        $this->actingAs($agent)->postJson($url, ['answers' => [
            $q1['question']->id => $q1['correct']->id,
            $q2['question']->id => $q2['correct']->id,
        ]])->assertOk()->assertJsonPath('data.passed', true);
    }

    public function test_raising_the_pass_mark_later_does_not_un_pass_a_recorded_attempt(): void
    {
        [, $agent, $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');
        $q2 = $this->makeQuestion($lesson, 'q2');
        $lesson->update(['quiz_pass_percent' => 50, 'quiz_blocks_completion' => true]);

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", ['answers' => [
                $q1['question']->id => $q1['correct']->id,
                $q2['question']->id => $q2['wrong']->id,
            ]])
            ->assertOk()
            ->assertJsonPath('data.passed', true);

        // The admin tightens the rule afterwards. `passed` is frozen on the
        // attempt row, so the gate still reads a pass — the same guarantee
        // ADR-029 §3 makes for module_completions.
        $lesson->update(['quiz_pass_percent' => 100]);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    // =================================================================
    // ADR-029 §2.7 — feedback never reveals the right answer
    // =================================================================

    public function test_the_attempt_result_never_reveals_the_correct_option(): void
    {
        [$company, $agent, $lesson] = $this->makeLesson();

        /*
         * Inserted with EXPLICIT, deliberately huge ids so the raw-payload
         * scan below is unambiguous. With normal auto-increment ids the
         * correct option might be id 1 or 3, which collide with unrelated
         * small integers in the response (a question id, a score, a count)
         * and a "does 1 appear?" assertion would prove nothing.
         *
         * The option TEXTS are unique random strings for the same reason.
         */
        $correctTextQ1 = 'CORRECT-'.Str::random(16);
        $correctTextQ2 = 'CORRECT-'.Str::random(16);

        // ADR-030 §2.1 — the lesson's quiz owns the questions.
        $quiz = app(QuizService::class)->ensureForLesson($lesson);

        $q1 = ModuleLessonQuizQuestion::create([
            'company_id' => $company->id, 'quiz_id' => $quiz->id, 'question_text' => 'q1',
        ]);
        $q2 = ModuleLessonQuizQuestion::create([
            'company_id' => $company->id, 'quiz_id' => $quiz->id, 'question_text' => 'q2',
        ]);

        DB::table('module_lesson_quiz_options')->insert([
            ['id' => 900001, 'company_id' => $company->id, 'module_lesson_quiz_question_id' => $q1->id, 'option_text' => $correctTextQ1, 'is_correct' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 900002, 'company_id' => $company->id, 'module_lesson_quiz_question_id' => $q1->id, 'option_text' => 'decoy-a', 'is_correct' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 900003, 'company_id' => $company->id, 'module_lesson_quiz_question_id' => $q2->id, 'option_text' => $correctTextQ2, 'is_correct' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 900004, 'company_id' => $company->id, 'module_lesson_quiz_question_id' => $q2->id, 'option_text' => 'decoy-b', 'is_correct' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // BOTH answered WRONG — the case ADR-029 §2.7 is actually about.
        // (For a question answered correctly the learner already knows the
        // right option: it is the one they picked.)
        $response = $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1->id => 900002, $q2->id => 900004],
            ])
            ->assertOk()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.passed', false);

        // They ARE told which of their own answers were wrong (§2.7).
        $response->assertJsonPath('data.results.0.is_correct', false)
            ->assertJsonPath('data.results.1.is_correct', false);

        // Decoded and re-encoded unescaped, because Laravel escapes non-ASCII
        // to \uXXXX by default and a raw-string scan would check the wrong
        // bytes (same technique as LessonProgressCompletionTest).
        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString($correctTextQ1, $payload, 'the correct option TEXT must never be returned');
        $this->assertStringNotContainsString($correctTextQ2, $payload);
        $this->assertStringNotContainsString('900001', $payload, 'the correct option ID must never be returned');
        $this->assertStringNotContainsString('900003', $payload);
        // No option id at all, in fact — not even the learner's own picks,
        // which the client already knows.
        $this->assertStringNotContainsString('900002', $payload);
        $this->assertStringNotContainsString('900004', $payload);
        $this->assertStringNotContainsString('is_correct_option', $payload);
        // The PASS MARK is a threshold, and ADR-028 §4 settled that
        // thresholds are not shown to learners.
        $this->assertStringNotContainsString('pass_percent', $payload);

        // The key sets are the whole contract, asserted exactly so a future
        // added field fails this test rather than shipping quietly.
        $data = $response->json('data');
        $this->assertSame(
            ['id', 'module_lesson_id', 'score', 'total_questions', 'passed', 'attempted_at', 'results'],
            array_keys($data),
        );
        foreach ($data['results'] as $result) {
            $this->assertSame(['question_id', 'answered', 'is_correct'], array_keys($result));
        }
    }

    public function test_is_correct_stays_masked_for_an_agent_but_not_for_an_admin(): void
    {
        [$company, $agent, $lesson] = $this->makeLesson();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->makeQuestion($lesson, 'q1');

        $agentLesson = $this->lessonFromModule($agent, $lesson);
        foreach ($agentLesson['quiz_questions'][0]['options'] as $option) {
            // ADR-029 §2.7 — unchanged from today's behaviour.
            $this->assertNull($option['is_correct']);
        }

        $adminLesson = $this->lessonFromModule($admin, $lesson);
        $this->assertTrue(
            collect($adminLesson['quiz_questions'][0]['options'])->firstWhere('option_text', 'right')['is_correct']
        );
    }

    // =================================================================
    // ADR-029 §2.1 — ANY lesson may carry a quiz
    // =================================================================

    public function test_a_video_lesson_exposes_its_quiz_questions(): void
    {
        // Before ADR-029, ModuleLessonResource hid quiz_questions behind
        // `content_type === Quiz`, so an end-of-lesson quiz on a video —
        // the thing the feature is named after — was invisible.
        [, $agent, $lesson] = $this->makeLesson(['content_type' => 'video']);
        $this->makeQuestion($lesson, 'what did the video say?');

        $payload = $this->lessonFromModule($agent, $lesson);

        $this->assertSame(1, $payload['quiz_question_count']);
        $this->assertTrue($payload['quiz_unlocked']);
        $this->assertSame('what did the video say?', $payload['quiz_questions'][0]['question_text']);
    }

    public function test_a_lesson_without_questions_reports_no_quiz(): void
    {
        [, $agent, $lesson] = $this->makeLesson();

        $payload = $this->lessonFromModule($agent, $lesson);

        $this->assertSame(0, $payload['quiz_question_count']);
        $this->assertFalse($payload['quiz_unlocked']);
        $this->assertNull($payload['quiz_passed']);
        $this->assertArrayNotHasKey('quiz_questions', $payload);
    }

    // =================================================================
    // ADR-029 §2.2 — the quiz is locked until the content gate is met
    // =================================================================

    public function test_the_quiz_is_locked_until_the_content_gate_is_met(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeGatedVideoLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');

        // 1. The resource says locked, and withholds the questions — the
        //    flag alone would leave the gate in the client's hands.
        $locked = $this->lessonFromModule($agent, $lesson);
        $this->assertFalse($locked['quiz_unlocked']);
        $this->assertSame(1, $locked['quiz_question_count']);
        $this->assertArrayNotHasKey('quiz_questions', $locked);

        // 2. And a raw POST is refused regardless of what the client
        //    believes (the whole point — the flag is not the enforcement).
        $blocked = $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['correct']->id],
            ])
            ->assertUnprocessable();

        $this->assertSame(
            'กรุณาเรียนเนื้อหาให้ครบก่อนจึงจะทำแบบทดสอบได้',
            $blocked->json('errors.module_lesson_id.0'),
        );
        $this->assertSame(0, ModuleLessonQuizAttempt::withoutGlobalScopes()->count());

        // 3. Watch past the 80% threshold (480s of 600s) and it opens.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 480]);

        $unlocked = $this->lessonFromModule($agent, $lesson);
        $this->assertTrue($unlocked['quiz_unlocked']);
        $this->assertCount(1, $unlocked['quiz_questions']);

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['correct']->id],
            ])
            ->assertOk();
    }

    public function test_the_locked_quiz_message_leaks_no_progress_figure(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeGatedVideoLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 372]);

        $response = $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['correct']->id],
            ])
            ->assertUnprocessable();

        // ADR-028 §4 — actionable, never specific.
        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('372', $payload);
        $this->assertStringNotContainsString('480', $payload);
        $this->assertStringNotContainsString('600', $payload);
        $this->assertStringNotContainsString('80', $payload);
        $this->assertStringNotContainsString('%', $payload);
    }

    // =================================================================
    // ADR-029 §2.6 — blocking vs advisory
    // =================================================================

    public function test_a_blocking_quiz_blocks_completion_until_it_is_passed(): void
    {
        [, $agent, $lesson] = $this->makeLesson(['quiz_blocks_completion' => true]);
        $q1 = $this->makeQuestion($lesson, 'q1');

        $blocked = $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_lesson_id');

        $this->assertSame(
            'กรุณาทำแบบทดสอบท้ายบทให้ผ่านก่อนจึงจะกดเรียนจบได้',
            $blocked->json('errors.module_lesson_id.0'),
        );
        $this->assertSame(0, ModuleCompletion::withoutGlobalScopes()->count());

        // Fail it — still blocked. A recorded attempt is not a pass.
        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['wrong']->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.passed', false);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable();

        // Pass it — completion is earned.
        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['correct']->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.passed', true);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    public function test_the_blocked_completion_error_leaks_neither_the_pass_mark_nor_the_score(): void
    {
        [, $agent, $lesson] = $this->makeLesson(['quiz_blocks_completion' => true, 'quiz_pass_percent' => 75]);
        $questions = collect(range(1, 4))->map(fn ($i) => $this->makeQuestion($lesson, "q{$i}"));

        // 1 of 4 = 25%, well under this lesson's 75.
        $this->actingAs($agent)->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
            'answers' => $questions->mapWithKeys(fn ($q, $i) => [
                $q['question']->id => ($i === 0 ? $q['correct']->id : $q['wrong']->id),
            ])->all(),
        ])->assertOk();

        $response = $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable();

        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('75', $payload, 'the pass mark must not leak');
        $this->assertStringNotContainsString('25', $payload);
        $this->assertStringNotContainsString('%', $payload);
        $this->assertStringNotContainsString('score', $payload);
    }

    public function test_an_advisory_quiz_never_blocks_completion(): void
    {
        // ADR-029 §2.6 — the DEFAULT. Failing is recorded for the admin and
        // gates nothing.
        [, $agent, $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['wrong']->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.passed', false);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();

        // ...and the failed attempt is still on record.
        $this->assertSame(1, ModuleLessonQuizAttempt::withoutGlobalScopes()->count());
    }

    public function test_a_blocking_flag_on_a_lesson_with_no_questions_does_not_lock_it(): void
    {
        // An admin ticking "quiz required" on a lesson that has no quiz
        // would otherwise create an unpassable gate with no visible cause.
        [, $agent, $lesson] = $this->makeLesson(['quiz_blocks_completion' => true]);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    public function test_the_content_gate_still_blocks_before_the_quiz_gate_is_consulted(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeGatedVideoLesson();
        $lesson->update(['quiz_blocks_completion' => true]);
        $this->makeQuestion($lesson, 'q1');

        $blocked = $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable();

        // ADR-028's message, not ADR-029's — the learner has not watched
        // the video, so "go and pass the quiz" would be useless advice
        // (the quiz is not even open to them yet).
        $this->assertSame(
            'กรุณาดูวิดีโอให้ครบก่อนจึงจะกดเรียนจบได้',
            $blocked->json('errors.module_lesson_id.0'),
        );
    }

    // =================================================================
    // ADR-029 §2.5 — unlimited retries
    // =================================================================

    public function test_retries_are_unlimited_and_every_attempt_is_recorded(): void
    {
        [, $agent, $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');
        $url = "/api/v1/module-lessons/{$lesson->id}/quiz-attempts";

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($agent)
                ->postJson($url, ['answers' => [$q1['question']->id => $q1['wrong']->id]])
                ->assertOk();
        }

        // No cap, no cooldown — and the admin can see all five (§2.5).
        $this->assertSame(5, ModuleLessonQuizAttempt::withoutGlobalScopes()->count());
    }

    // =================================================================
    // NON-NEGOTIABLE 1 — grandfathering (ADR-029 §3)
    // =================================================================

    public function test_an_existing_completion_survives_a_quiz_being_added_afterwards(): void
    {
        [$company, $agent, $lesson] = $this->makeLesson();

        // Completed yesterday, under the pre-ADR-029 rule.
        $existing = ModuleCompletion::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'module_lesson_id' => $lesson->id,
            'completed_at' => now()->subDay(),
        ]);

        // Today the admin adds a BLOCKING quiz the learner has never seen.
        $this->makeQuestion($lesson, 'q1');
        $lesson->update(['quiz_blocks_completion' => true]);

        // It is still listed...
        $this->actingAs($agent)
            ->getJson('/api/v1/module-completions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // ...and a repeat POST is still the no-op it always was, NOT a
        // re-evaluation. 200 rather than 201 because the row was not
        // recently created (JsonResource::calculateStatus).
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertOk()
            ->assertJsonPath('data.id', $existing->id);

        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()->count());
        $this->assertTrue($existing->fresh()->exists);
    }

    // =================================================================
    // NON-NEGOTIABLE 2 — passing a quiz awards NO XP (ADR-029 §4 item 1)
    // =================================================================

    public function test_passing_a_quiz_awards_no_xp(): void
    {
        $this->seedModuleCompletedXpRule(10);
        [, $agent, $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['correct']->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.passed', true);

        /*
         * ADR-029 §4 item 1 is UNRESOLVED: "whether XP should be awarded for
         * passing a quiz... ask before adding — XP feeds promotion bonuses
         * that pay real money."
         *
         * Deliberately unfiltered: the WHOLE ledger, for anyone, is empty.
         * §2.5's unlimited retries would make any such award farmable by
         * construction, so this assertion is also the guard against someone
         * adding one without reopening the question.
         */
        $this->assertSame(0, XpLedger::withoutGlobalScopes()->count());
    }

    public function test_the_lessons_own_completion_xp_is_unaffected(): void
    {
        // Regression guard for the assertion above: withholding XP on the
        // QUIZ must not have withheld it on the lesson completion, which is
        // the path BR-5 source (a) is actually about.
        $this->seedModuleCompletedXpRule(10);
        [, $agent, $lesson] = $this->makeLesson(['quiz_blocks_completion' => true]);
        $q1 = $this->makeQuestion($lesson, 'q1');

        $this->actingAs($agent)->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
            'answers' => [$q1['question']->id => $q1['correct']->id],
        ])->assertOk();

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();

        $ledger = XpLedger::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('source_type', GamificationSourceType::ModuleCompleted)
            ->get();

        $this->assertCount(1, $ledger);
        $this->assertSame(10, (int) $ledger->first()->xp_awarded);
    }

    // =================================================================
    // The ADR-028 admin override must still work (ADR-029 §3)
    // =================================================================

    public function test_an_admin_can_override_a_quiz_blocked_lesson_and_it_is_audit_logged(): void
    {
        [$company, $agent, $lesson] = $this->makeLesson(['quiz_blocks_completion' => true]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->makeQuestion($lesson, 'q1');

        // The learner cannot get past it themselves...
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable();

        // ...and the pressure valve still opens (ADR-028 §2.3 guard 2).
        $this->actingAs($admin)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/completions/override", ['user_id' => $agent->id])
            ->assertCreated();

        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('module_lesson_id', $lesson->id)
            ->count());

        // §6 — it affects certification (a completion feeds the BR-1 gate).
        $log = AuditLog::where('action', 'module_completion.admin_override')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($agent->id, $log->new_values['user_id']);
    }

    // =================================================================
    // The admin readout (ADR-029 §2.5) — score only (§4 item 2)
    // =================================================================

    public function test_an_admin_can_read_the_attempts_and_sees_only_the_score(): void
    {
        [$company, $agent, $lesson] = $this->makeLesson();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $q1 = $this->makeQuestion($lesson, 'q1');

        $this->actingAs($agent)->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
            'answers' => [$q1['question']->id => $q1['wrong']->id],
        ])->assertOk();

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts")
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $agent->id)
            ->assertJsonPath('data.0.score', 0)
            ->assertJsonPath('data.0.total_questions', 1)
            ->assertJsonPath('data.0.passed', false);

        // ADR-029 §4 item 2 is unresolved and PDPA-adjacent: score only.
        $this->assertSame(
            ['id', 'user_id', 'user', 'module_lesson_id', 'score', 'total_questions', 'passed', 'attempted_at'],
            array_keys($response->json('data.0')),
        );

        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString((string) $q1['wrong']->option_text, $payload);
        $this->assertStringNotContainsString('answers', $payload);
        $this->assertStringNotContainsString('results', $payload);
    }

    public function test_an_agent_cannot_read_the_attempts_readout(): void
    {
        [, $agent, $lesson] = $this->makeLesson();

        // Gated on ModulePolicy::update, not ::view — an Agent may view a
        // module to learn from it and must not read other learners' results.
        $this->actingAs($agent)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts")
            ->assertForbidden();
    }

    // =================================================================
    // BR-6 / §5 rule 5 — tenant isolation
    // =================================================================

    public function test_another_companys_agent_cannot_attempt_the_quiz(): void
    {
        [, , $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');

        $outsider = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        // TenantScope makes the lesson id simply absent at route-model
        // binding, so this is a 404 before the handler runs.
        $this->actingAs($outsider)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['correct']->id],
            ])
            ->assertNotFound();

        $this->assertSame(0, ModuleLessonQuizAttempt::withoutGlobalScopes()->count());
    }

    public function test_another_companys_admin_cannot_read_the_attempts_readout(): void
    {
        [, , $lesson] = $this->makeLesson();

        $outsideAdmin = User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->actingAs($outsideAdmin)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts")
            ->assertNotFound();
    }

    public function test_an_option_from_another_company_is_rejected(): void
    {
        [, $agent, $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');

        [, , $foreignLesson] = $this->makeLesson();
        $foreign = $this->makeQuestion($foreignLesson, 'foreign');

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $foreign['correct']->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answers.'.$q1['question']->id);
    }

    public function test_attempting_a_quiz_requires_authentication(): void
    {
        [, , $lesson] = $this->makeLesson();

        $this->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", ['answers' => [1 => 1]])
            ->assertUnauthorized();
    }

    public function test_an_empty_submission_is_rejected(): void
    {
        [, $agent, $lesson] = $this->makeLesson();
        $this->makeQuestion($lesson, 'q1');

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", ['answers' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('answers');
    }

    public function test_a_client_supplied_score_or_passed_flag_is_ignored(): void
    {
        [, $agent, $lesson] = $this->makeLesson();
        $q1 = $this->makeQuestion($lesson, 'q1');

        // CLAUDE.md §6 — self-grading would let anyone clear a
        // quiz_blocks_completion lesson and, through it, the BR-1 path.
        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['wrong']->id],
                'score' => 99,
                'passed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.passed', false);

        $this->assertFalse(ModuleLessonQuizAttempt::withoutGlobalScopes()->firstOrFail()->passed);
    }

    // =================================================================
    // BR-7 — the company pass mark is admin-editable, agent-invisible
    // =================================================================

    public function test_an_admin_can_read_and_update_the_company_quiz_pass_mark(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // The seeded platform default is the human's stated 80 (ADR-029 §2.4).
        $this->actingAs($admin)
            ->getJson('/api/v1/academy-completion-settings')
            ->assertOk()
            ->assertJsonPath('data.quiz_pass_percent', 80);

        $this->actingAs($admin)
            ->putJson('/api/v1/academy-completion-settings', [
                'video_watch_percent' => 80,
                'pdf_read_percent' => 100,
                'quiz_pass_percent' => 60,
            ])
            ->assertOk()
            ->assertJsonPath('data.quiz_pass_percent', 60);
    }

    public function test_a_zero_quiz_pass_mark_cannot_be_configured(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // 0% would mean "answering nothing passes" — the gate silently off
        // for a whole company, with no audit trail.
        $this->actingAs($admin)
            ->putJson('/api/v1/academy-completion-settings', [
                'video_watch_percent' => 80,
                'pdf_read_percent' => 100,
                'quiz_pass_percent' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quiz_pass_percent');
    }

    public function test_the_per_lesson_pass_mark_is_not_shown_to_an_agent(): void
    {
        [$company, $agent, $lesson] = $this->makeLesson(['quiz_pass_percent' => 65]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->makeQuestion($lesson, 'q1');

        // A threshold is the class of number ADR-028 §4 withholds from
        // learners; the admin needs it for the authoring form.
        $agentPayload = $this->lessonFromModule($agent, $lesson);
        $this->assertArrayNotHasKey('quiz_pass_percent', $agentPayload);

        $adminPayload = $this->lessonFromModule($admin, $lesson);
        $this->assertSame(65, $adminPayload['quiz_pass_percent']);
    }

    /**
     * Lesson quizzes are embedded on the parent Module (Section) response
     * rather than exposed via a standalone GET — the same convention
     * ModuleLessonQuizTest uses.
     *
     * @return array<string, mixed>
     */
    private function lessonFromModule(User $actor, ModuleLesson $lesson): array
    {
        $module = $this->actingAs($actor)
            ->getJson("/api/v1/modules/{$lesson->module_id}")
            ->assertOk()
            ->json('data');

        return collect($module['lessons'])->firstWhere('id', $lesson->id);
    }
}
