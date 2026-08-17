<?php

namespace Tests\Feature\Academy;

use App\Enums\GamificationSourceType;
use App\Models\AcademyCompletionSetting;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\GamificationRule;
use App\Models\Module;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonQuizOption;
use App\Models\ModuleLessonQuizQuestion;
use App\Models\User;
use App\Models\XpLedger;
use App\Services\Academy\QuizService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-165 — "Completion is recorded, not declared."
 *
 * ADR-028 §1 said completion is EARNED, not asserted — and then left a
 * button reading **"ทำเครื่องหมายว่าเรียนจบ"** (mark it as finished), which
 * is the language of asserting. TASK-165 §2 splits the two cases:
 *
 *   - VERIFIABLE (uploaded video / uploaded PDF): the server records the
 *     completion the moment its own gate is satisfied. No button.
 *   - NOT VERIFIABLE (embed, external link, downloadable file, a video we
 *     could never probe): unchanged — the learner still tells us.
 *
 * The file is ordered by risk, not by feature. §3.3 is first because it is
 * the case that gets missed: a quiz-blocked lesson read to 100% fires
 * nothing (correctly — the quiz is unmet), and after the quiz is passed NO
 * FURTHER PROGRESS PING EVER ARRIVES. Without a trigger on the quiz path
 * that learner is stuck at "not complete" having done everything asked.
 */
class AutomaticLessonCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function seedModuleCompletedXpRule(int $xpValue = 10): void
    {
        GamificationRule::create([
            'company_id' => null,
            'source_type' => GamificationSourceType::ModuleCompleted,
            'xp_value' => $xpValue,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $moduleAttributes
     * @return array{0: Company, 1: User, 2: ModuleLesson}
     */
    private function makeUploadedLesson(array $attributes = [], array $moduleAttributes = []): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(array_merge(
            ['cert_tier_id' => $tier->id],
            $moduleAttributes,
        ));

        $extension = ($attributes['content_type'] ?? 'video') === 'pdf' ? 'pdf' : 'mp4';
        $path = "academy-lessons/{$company->id}/1/".Str::uuid()->toString().'.'.$extension;
        Storage::disk('local')->put($path, 'fake bytes');

        $lesson = ModuleLesson::factory()->create(array_merge([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'source_type' => 'upload',
            'content_ref' => $path,
        ], $attributes));

        return [$company, $agent, $lesson];
    }

    /**
     * One question with a correct and an incorrect option, hung off the
     * lesson's own quiz (ADR-030 §2.1) through the same Service the
     * authoring endpoint uses.
     *
     * @return array{question: ModuleLessonQuizQuestion, correct: ModuleLessonQuizOption, wrong: ModuleLessonQuizOption}
     */
    private function makeQuestion(ModuleLesson $lesson, string $text = 'q'): array
    {
        $quiz = app(QuizService::class)->ensureForLesson($lesson);

        $question = ModuleLessonQuizQuestion::create([
            'company_id' => $lesson->company_id,
            'quiz_id' => $quiz->id,
            'question_text' => $text,
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

    private function completionCount(User $agent, ModuleLesson $lesson): int
    {
        return ModuleCompletion::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('module_lesson_id', $lesson->id)
            ->count();
    }

    private function xpCount(User $agent): int
    {
        return XpLedger::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('source_type', GamificationSourceType::ModuleCompleted)
            ->count();
    }

    // =================================================================
    // §3.3 — THE QUIZ PATH. Written first because it is the one that
    // gets missed.
    // =================================================================

    public function test_a_quiz_blocked_lesson_completes_on_passing_the_quiz_after_the_content_gate_was_already_met(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);

        [, $agent, $lesson] = $this->makeUploadedLesson([
            'content_type' => 'video',
            'duration_seconds' => 600,
            'quiz_blocks_completion' => true,
        ]);
        $q1 = $this->makeQuestion($lesson, 'q1');

        // 1. Watch the whole thing. The CONTENT half of the gate is now met
        //    and the quiz has opened (ADR-029 §2.2) — but the lesson is NOT
        //    complete, because isQuizSatisfied() is false. This half must
        //    hold, or the blocking quiz would mean nothing.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 600])
            ->assertOk()
            ->assertExactJson(['completed' => false]);

        $this->assertSame(0, $this->completionCount($agent, $lesson));

        // 2. Pass the quiz. THIS IS THE WHOLE POINT: no further progress
        //    ping will ever arrive — there is nothing left to watch — so if
        //    the quiz-attempt path does not fire the check, this learner is
        //    stuck at "not complete" forever having done everything.
        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['correct']->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.passed', true);

        $this->assertSame(1, $this->completionCount($agent, $lesson), 'passing the blocking quiz must record the completion');

        // BR-5 source (a) — earned, so XP is awarded. Exactly once.
        $this->assertSame(1, $this->xpCount($agent));
    }

    public function test_failing_the_blocking_quiz_records_no_completion(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);

        [, $agent, $lesson] = $this->makeUploadedLesson([
            'content_type' => 'video',
            'duration_seconds' => 600,
            'quiz_blocks_completion' => true,
        ]);
        $q1 = $this->makeQuestion($lesson, 'q1');

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 600]);

        // The mirror of the test above: the trigger must be the GATE, not
        // "a quiz was submitted".
        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['wrong']->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.passed', false);

        $this->assertSame(0, $this->completionCount($agent, $lesson));
        $this->assertSame(0, $this->xpCount($agent));
    }

    public function test_passing_an_advisory_quiz_on_an_unwatched_lesson_completes_nothing(): void
    {
        Storage::fake('local');

        // quiz_blocks_completion defaults false — the quiz is advisory
        // (ADR-029 §2.6) — so the CONTENT gate is the only thing standing.
        // ADR-029 §2.2 refuses the attempt outright on an unwatched lesson,
        // and no completion may appear from the refusal either.
        [, $agent, $lesson] = $this->makeUploadedLesson([
            'content_type' => 'video',
            'duration_seconds' => 600,
        ]);
        $q1 = $this->makeQuestion($lesson, 'q1');

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/quiz-attempts", [
                'answers' => [$q1['question']->id => $q1['correct']->id],
            ])
            ->assertUnprocessable();

        $this->assertSame(0, $this->completionCount($agent, $lesson));
    }

    // =================================================================
    // §4 AC 1 + 2 — a PDF / a video completes with NO user action
    // =================================================================

    public function test_reading_an_uploaded_pdf_to_the_configured_percentage_completes_it_with_no_user_action(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);

        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'pdf', 'page_count' => 10]);
        $url = "/api/v1/module-lessons/{$lesson->id}/progress";

        // Partway: nothing recorded (the default pdf_read_percent is 100).
        $this->actingAs($agent)->putJson($url, ['last_page' => 9])
            ->assertOk()
            ->assertExactJson(['completed' => false]);

        $this->assertSame(0, $this->completionCount($agent, $lesson));

        // The last page. NO POST to /module-completions anywhere in this
        // test — that is the acceptance criterion.
        $this->actingAs($agent)->putJson($url, ['last_page' => 10])
            ->assertOk()
            ->assertExactJson(['completed' => true]);

        $this->assertSame(1, $this->completionCount($agent, $lesson));
        $this->assertSame(1, $this->xpCount($agent));

        // BR-5 — and with the CONFIGURED value, not a zeroed award. A
        // change that reached awardXp() with nothing in it would still pass
        // a bare row count.
        $this->assertSame(10, (int) XpLedger::withoutGlobalScopes()->firstOrFail()->xp_awarded);
    }

    public function test_watching_an_uploaded_video_past_the_configured_percentage_completes_it_with_no_user_action(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);

        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $url = "/api/v1/module-lessons/{$lesson->id}/progress";

        // 479 of 600 is under the default 80% (=480). Nothing yet.
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 479])
            ->assertOk()
            ->assertExactJson(['completed' => false]);

        $this->assertSame(0, $this->completionCount($agent, $lesson));

        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 480])
            ->assertOk()
            ->assertExactJson(['completed' => true]);

        $this->assertSame(1, $this->completionCount($agent, $lesson));
        $this->assertSame(1, $this->xpCount($agent));
    }

    public function test_the_threshold_that_triggers_the_automatic_completion_is_company_config(): void
    {
        Storage::fake('local');
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        // BR-7 — the percentage is never a constant. Auto-completion must
        // read the same config the button-driven gate always did.
        AcademyCompletionSetting::create([
            'company_id' => $company->id,
            'video_watch_percent' => 50,
            'pdf_read_percent' => 100,
        ]);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 300])
            ->assertOk()
            ->assertExactJson(['completed' => true]);
    }

    // =================================================================
    // §4 AC 6 — repeat pings do not duplicate the row or re-award XP
    // =================================================================

    public function test_repeat_progress_pings_after_completion_neither_duplicate_the_row_nor_re_award_xp(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);

        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $url = "/api/v1/module-lessons/{$lesson->id}/progress";

        // The realistic shape: a learner keeps the tab open past the
        // threshold, so the throttled reporter keeps firing every 15s.
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 480])->assertExactJson(['completed' => true]);
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 520])->assertExactJson(['completed' => true]);
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 600])->assertExactJson(['completed' => true]);
        // ...and then scrubs back and rewatches, which is not a second
        // first completion either.
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 30])->assertExactJson(['completed' => true]);

        // `wasRecentlyCreated` inside ModuleCompletionService::record() is
        // what guards this — proven here rather than assumed.
        $this->assertSame(1, $this->completionCount($agent, $lesson));
        $this->assertSame(1, $this->xpCount($agent));

        // And the manual POST is still the no-op it has always been.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertOk();

        $this->assertSame(1, $this->completionCount($agent, $lesson));
        $this->assertSame(1, $this->xpCount($agent));
    }

    // =================================================================
    // §4 AC 4 — the NOT-VERIFIABLE half is untouched: button, and it works
    // =================================================================

    public function test_an_embedded_video_is_not_automatic_and_still_completes_by_the_button(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'video',
            'source_type' => 'embed',
            'content_ref' => 'https://www.youtube.com/embed/abc123',
        ]);

        // The resource tells the client to KEEP the button (§3.1).
        $this->assertFalse($this->lessonFromApi($agent, $lesson)['completion_is_automatic']);

        // ...and pressing it still works, exactly as before.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    public function test_an_external_link_lesson_is_not_automatic_and_still_completes_by_the_button(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'link',
            'source_type' => 'embed',
            'content_ref' => 'https://example.com/handbook',
        ]);

        $this->assertFalse($this->lessonFromApi($agent, $lesson)['completion_is_automatic']);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    public function test_a_downloadable_file_stays_on_the_button_because_it_can_be_read_outside_the_app(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson([
            'content_type' => 'pdf',
            'page_count' => 10,
            'is_downloadable' => true,
        ]);

        // ADR-028 §2.3, stated openly: in-app position measures nothing for
        // a file the learner may keep, so auto-completing off it would be a
        // completion asserted by the reader rather than earned.
        $this->assertFalse($this->lessonFromApi($agent, $lesson)['completion_is_automatic']);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_page' => 10])
            ->assertOk()
            ->assertExactJson(['completed' => false]);

        $this->assertSame(0, $this->completionCount($agent, $lesson));

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    public function test_an_uploaded_video_with_no_probed_duration_stays_on_the_button(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson([
            'content_type' => 'video',
            'duration_seconds' => null,
        ]);

        /*
         * The ffprobe fail-open (LessonCompletionGate::videoEarned). There
         * is no honest denominator, so there is nothing to MEASURE — and
         * auto-completing would fire off the very first position ping, i.e.
         * "opened it once" would become "finished it". TASK-165 §2 puts
         * anything the gate cannot measure on the button side, and the gate
         * still fails OPEN, so the button works.
         *
         * CONFIRMED by ag-lead (2026-08-11). Not a BR-7 business value —
         * it follows from ADR-028 §1, so it was mine to settle:
         *
         * A missing duration is a missing DENOMINATOR. "Watched 80%" has no
         * answer, and the only thing an auto-complete could key off is the
         * first position ping — which would make "opened it once" mean
         * "finished it", the exact assertion ADR-028 §1 exists to forbid.
         *
         * So an unmeasurable upload sits on the SAME side as an embed video
         * or an external link: the system cannot measure it, therefore the
         * learner tells us, via the button. One rule, one place, no special
         * case for "upload whose ffprobe failed".
         *
         * The learner is never stuck: the gate still fails OPEN, so the
         * button records the completion the moment they press it. What they
         * lose is the automation, not the lesson.
         */
        $this->assertFalse($this->lessonFromApi($agent, $lesson)['completion_is_automatic']);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 5])
            ->assertOk()
            ->assertExactJson(['completed' => false]);

        $this->assertSame(0, $this->completionCount($agent, $lesson));

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    // =================================================================
    // §3.1 — ONE PREDICATE. The resource exposes the GATE's answer.
    // =================================================================

    public function test_the_resource_flag_is_the_gates_own_answer_for_every_content_shape(): void
    {
        Storage::fake('local');

        [$company, $agent, $uploadedVideo] = $this->makeUploadedLesson([
            'content_type' => 'video',
            'duration_seconds' => 600,
        ]);
        $module = $uploadedVideo->module;

        $uploadedPdf = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'pdf',
            'source_type' => 'upload',
            'content_ref' => 'academy-lessons/x.pdf',
            'sort_order' => 1,
        ]);
        $embed = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'video',
            'source_type' => 'embed',
            'content_ref' => 'https://www.youtube.com/embed/abc',
            'sort_order' => 2,
        ]);
        $image = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'image',
            'source_type' => 'upload',
            'content_ref' => 'academy-lessons/x.png',
            'sort_order' => 3,
        ]);

        $expected = [
            $uploadedVideo->id => true,
            $uploadedPdf->id => true,
            $embed->id => false,
            // An uploaded image has no positional progress to read
            // (ADR-028 §2.3), so it stays on the button.
            $image->id => false,
        ];

        $lessons = $this->actingAs($agent)
            ->getJson("/api/v1/modules/{$module->id}")
            ->assertOk()
            ->json('data.lessons');

        $seen = [];

        foreach ($lessons as $row) {
            $seen[$row['id']] = $row['completion_is_automatic'];
        }

        foreach ($expected as $lessonId => $isAutomatic) {
            $this->assertSame($isAutomatic, $seen[$lessonId] ?? null, "completion_is_automatic for lesson {$lessonId}");
        }
    }

    // =================================================================
    // §4 AC 5 — an ADR-031 LOCKED lesson records nothing
    // =================================================================

    public function test_a_sequentially_locked_lesson_records_no_automatic_completion(): void
    {
        Storage::fake('local');

        [$company, $agent, $first] = $this->makeUploadedLesson(
            ['content_type' => 'video', 'duration_seconds' => 600, 'sort_order' => 0],
            ['enforce_sequential' => true],
        );

        $second = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $first->module_id,
            'content_type' => 'video',
            'source_type' => 'upload',
            'content_ref' => 'academy-lessons/second.mp4',
            'duration_seconds' => 600,
            'sort_order' => 1,
        ]);

        // ADR-031 §2.2 — the progress PUT is refused outright, so there is
        // no `max_*` to bank and nothing for the completion check to read.
        // Asserted at the write endpoint AND at the row count, because the
        // second is what a future refactor would break silently.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$second->id}/progress", ['last_position_seconds' => 600])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_lesson_id');

        $this->assertSame(0, $this->completionCount($agent, $second));
        $this->assertSame(0, ModuleCompletion::withoutGlobalScopes()->count());
    }

    public function test_a_dripped_section_records_no_automatic_completion(): void
    {
        Storage::fake('local');

        [, $agent, $lesson] = $this->makeUploadedLesson(
            ['content_type' => 'video', 'duration_seconds' => 600],
            ['drip_days' => 7],
        );

        // ADR-031 §2.3 — the Section is not open yet (the anchor is the
        // learner's approval/creation date, which is today).
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 600])
            ->assertUnprocessable();

        $this->assertSame(0, ModuleCompletion::withoutGlobalScopes()->count());
    }

    public function test_a_lock_applied_after_the_progress_was_banked_still_records_nothing(): void
    {
        Storage::fake('local');

        // The sharper version of the two above: the learner legitimately
        // watched the whole lesson while the Section was open, THEN the
        // admin switched sequential unlock on. The next ping must not walk
        // the banked max_* through into a completion — completeIfEarned()
        // consults LessonAccessGate itself and does not rely on the PUT
        // having been refused.
        [$company, $agent, $first] = $this->makeUploadedLesson(
            ['content_type' => 'video', 'duration_seconds' => 600, 'sort_order' => 0],
        );

        $second = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $first->module_id,
            'content_type' => 'video',
            'source_type' => 'upload',
            'content_ref' => 'academy-lessons/second.mp4',
            'duration_seconds' => 600,
            'sort_order' => 1,
        ]);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$second->id}/progress", ['last_position_seconds' => 400])
            ->assertOk()
            ->assertExactJson(['completed' => false]);

        $first->module->update(['enforce_sequential' => true]);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$second->id}/progress", ['last_position_seconds' => 600])
            ->assertUnprocessable();

        $this->assertSame(0, ModuleCompletion::withoutGlobalScopes()->count());
    }

    // =================================================================
    // §4 AC 7 — the ADMIN OVERRIDE is untouched, and still awards no XP
    // =================================================================

    public function test_the_admin_override_still_writes_the_completion_and_still_awards_no_xp(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);

        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/completions/override", ['user_id' => $agent->id])
            ->assertCreated();

        $this->assertSame(1, $this->completionCount($agent, $lesson));
        $this->assertSame(0, $this->xpCount($agent), 'ADR-028 §4.1 — an override awards no XP');

        // ...and the obvious farm is still closed: override first, then
        // genuinely watch it. The completion already exists, so there is no
        // second FIRST completion for the automatic path to reward.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 600])
            ->assertOk()
            ->assertExactJson(['completed' => true]);

        $this->assertSame(1, $this->completionCount($agent, $lesson));
        $this->assertSame(0, $this->xpCount($agent), 'an automatic ping after an override must not award the withheld XP');
    }

    // =================================================================
    // §3.4 — the response says WHETHER, never HOW FAR (ADR-028 §4)
    // =================================================================

    public function test_the_progress_response_is_one_boolean_and_leaks_no_measurement(): void
    {
        Storage::fake('local');
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        AcademyCompletionSetting::create([
            'company_id' => $company->id,
            'video_watch_percent' => 80,
            'pdf_read_percent' => 100,
        ]);

        $response = $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 372])
            ->assertOk();

        // The key set IS the contract — a future added field fails here
        // rather than shipping quietly, which is the same construction the
        // learner-scoped resume read uses.
        $this->assertSame(['completed'], array_keys($response->json()));
        $this->assertFalse($response->json('completed'));

        // ...and a raw scan on top. Unescaped, because Laravel escapes Thai
        // to \uXXXX by default and a raw-string scan would check the wrong
        // thing.
        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('372', $payload, 'the recorded position must not leak');
        $this->assertStringNotContainsString('600', $payload, 'duration_seconds must not leak');
        $this->assertStringNotContainsString('80', $payload, 'the configured threshold must not leak');
        $this->assertStringNotContainsString('%', $payload);
        $this->assertStringNotContainsString('max', $payload);
    }

    // =================================================================
    // BR-6 — the automatic path is not a way around tenant isolation
    // =================================================================

    public function test_another_companys_agent_cannot_trigger_an_automatic_completion(): void
    {
        Storage::fake('local');
        [, , $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $outsider = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        // §5 rule 5 — TenantScope makes the id simply absent at
        // route-model binding, so this never reaches the service.
        $this->actingAs($outsider)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 600])
            ->assertNotFound();

        $this->assertSame(0, ModuleCompletion::withoutGlobalScopes()->count());
    }

    /**
     * One lesson row as the LEARNER's API sees it.
     *
     * Read through GET /modules/{module} rather than from the model, so
     * these assertions describe what the Vue client actually receives.
     *
     * @return array<string, mixed>
     */
    private function lessonFromApi(User $agent, ModuleLesson $lesson): array
    {
        $lessons = $this->actingAs($agent)
            ->getJson("/api/v1/modules/{$lesson->module_id}")
            ->assertOk()
            ->json('data.lessons');

        foreach ($lessons as $row) {
            if ($row['id'] === $lesson->id) {
                return $row;
            }
        }

        $this->fail("lesson {$lesson->id} was not present in the module payload");
    }
}
