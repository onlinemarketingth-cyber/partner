<?php

namespace Tests\Feature\Academy;

use App\Models\AuditLog;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonProgress;
use App\Models\ModuleLessonQuizAttempt;
use App\Models\ModuleLessonQuizOption;
use App\Models\ModuleLessonQuizQuestion;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-151 / ADR-031 — reorder, sequential unlock, drip, optional lessons.
 *
 * The three non-negotiables have their own sections at the bottom:
 *
 *   1. NOTHING CHANGES FOR EXISTING DATA (both flags default off/null).
 *   2. The ADR-028 / ADR-029 gates still work.
 *   3. The ADR-028 admin override can still unstick a learner stranded
 *      behind a sequential lock — the release valve §2.2 depends on.
 */
class AcademySequencingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Section with N lessons in a known order.
     *
     * The lessons are content_type=video with source_type NULL (an embed),
     * which LessonCompletionGate treats as "not verifiable, fall back to the
     * button" (ADR-028 §2.3). That keeps these tests about ADR-031's ACCESS
     * gate and not about ADR-028's completion gate — the two are exercised
     * together in the "gates still work" section below.
     *
     * @param  array<int, array<string, mixed>>  $lessonAttributes
     * @return array{0: Company, 1: User, 2: Module, 3: \Illuminate\Support\Collection<int, ModuleLesson>}
     */
    private function makeSection(array $lessonAttributes = [], array $moduleAttributes = []): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();

        $module = Module::factory()->for($company)->create(array_merge(
            ['cert_tier_id' => $tier->id],
            $moduleAttributes,
        ));

        $lessons = collect($lessonAttributes)->values()->map(fn (array $attrs, int $i) => ModuleLesson::factory()->create(array_merge([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'video',
            'source_type' => null,
            'sort_order' => $i,
        ], $attrs)));

        return [$company, $agent, $module, $lessons];
    }

    private function makeUploadedLesson(Module $module, int $sortOrder, array $attrs = []): ModuleLesson
    {
        $path = "academy-lessons/{$module->company_id}/x/".Str::uuid()->toString().'.mp4';
        Storage::disk('local')->put($path, 'fake bytes');

        return ModuleLesson::factory()->create(array_merge([
            'company_id' => $module->company_id,
            'module_id' => $module->id,
            'content_type' => 'video',
            'source_type' => 'upload',
            'content_ref' => $path,
            'sort_order' => $sortOrder,
        ], $attrs));
    }

    private function complete(User $agent, ModuleLesson $lesson): void
    {
        ModuleCompletion::create([
            'company_id' => $lesson->company_id,
            'user_id' => $agent->id,
            'module_lesson_id' => $lesson->id,
            'completed_at' => now(),
        ]);
    }

    // =================================================================
    // §2.1 — BULK REORDER: SECTIONS WITHIN A CERT TIER
    // =================================================================

    public function test_an_admin_reorders_sections_in_one_call(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();

        $a = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'sort_order' => 0]);
        $b = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'sort_order' => 1]);
        $c = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'sort_order' => 2]);

        // ADR-031 §2.1 — ONE call carrying the FULL ordered list, not three.
        $this->actingAs($admin)
            ->putJson("/api/v1/cert-tiers/{$tier->id}/modules/reorder", [
                'module_ids' => [$c->id, $a->id, $b->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $c->id)
            ->assertJsonPath('data.1.id', $a->id)
            ->assertJsonPath('data.2.id', $b->id);

        $this->assertSame(0, $c->fresh()->sort_order);
        $this->assertSame(1, $a->fresh()->sort_order);
        $this->assertSame(2, $b->fresh()->sort_order);
    }

    public function test_a_section_from_another_cert_tier_is_rejected_and_nothing_is_written(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $otherTier = CertTier::factory()->create();

        $a = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'sort_order' => 0]);
        $b = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'sort_order' => 1]);
        $foreign = Module::factory()->for($company)->create(['cert_tier_id' => $otherTier->id, 'sort_order' => 0]);

        $this->actingAs($admin)
            ->putJson("/api/v1/cert-tiers/{$tier->id}/modules/reorder", [
                // The smuggled id is LAST, so a naive implementation that
                // wrote as it walked would already have moved $b before it
                // noticed. Nothing may have moved.
                'module_ids' => [$b->id, $a->id, $foreign->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_ids');

        $this->assertSame(0, $a->fresh()->sort_order);
        $this->assertSame(1, $b->fresh()->sort_order);
        $this->assertSame(0, $foreign->fresh()->sort_order);
    }

    public function test_another_companys_section_cannot_be_smuggled_into_a_reorder(): void
    {
        // BR-6 / §5 rule 5 — the obvious place to smuggle an id.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();

        $mine = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'sort_order' => 0]);

        $otherCompany = Company::factory()->create();
        $theirs = Module::factory()->for($otherCompany)->create(['cert_tier_id' => $tier->id, 'sort_order' => 0]);

        $this->actingAs($admin)
            ->putJson("/api/v1/cert-tiers/{$tier->id}/modules/reorder", [
                'module_ids' => [$theirs->id, $mine->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_ids');

        $this->assertSame(0, $mine->fresh()->sort_order);
        $this->assertSame(0, $theirs->fresh()->sort_order);
    }

    public function test_a_super_admin_cannot_reorder_across_two_companies_in_one_payload(): void
    {
        // A Super Admin is exempt from TenantScope, so the test above proves
        // nothing for them — this is the check that does (BR-6).
        $superAdmin = User::factory()->superAdmin()->create();
        $tier = CertTier::factory()->create();

        $a = Module::factory()->for(Company::factory()->create())->create(['cert_tier_id' => $tier->id, 'sort_order' => 0]);
        $b = Module::factory()->for(Company::factory()->create())->create(['cert_tier_id' => $tier->id, 'sort_order' => 0]);

        $this->actingAs($superAdmin)
            ->putJson("/api/v1/cert-tiers/{$tier->id}/modules/reorder", [
                'module_ids' => [$b->id, $a->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_ids');

        $this->assertSame(0, $a->fresh()->sort_order);
        $this->assertSame(0, $b->fresh()->sort_order);
    }

    public function test_an_incomplete_sibling_list_is_rejected(): void
    {
        // ADR-031 §2.1 says "the FULL ordered list". A partial list is not a
        // smaller reorder, it is a corrupt one: the omitted siblings keep
        // their old sort_order and collide with the new numbering.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();

        $a = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'sort_order' => 0]);
        $b = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'sort_order' => 1]);
        Module::factory()->for($company)->create(['cert_tier_id' => $tier->id, 'sort_order' => 2]);

        $this->actingAs($admin)
            ->putJson("/api/v1/cert-tiers/{$tier->id}/modules/reorder", [
                'module_ids' => [$b->id, $a->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_ids');

        $this->assertSame(0, $a->fresh()->sort_order);
        $this->assertSame(1, $b->fresh()->sort_order);
    }

    public function test_an_agent_cannot_reorder_sections(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $this->actingAs($agent)
            ->putJson("/api/v1/cert-tiers/{$tier->id}/modules/reorder", ['module_ids' => [$module->id]])
            ->assertForbidden();
    }

    // =================================================================
    // §2.1 — BULK REORDER: LESSONS WITHIN A SECTION
    // =================================================================

    public function test_an_admin_reorders_lessons_in_one_call(): void
    {
        [$company, , $module, $lessons] = $this->makeSection([[], [], []]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/modules/{$module->id}/lessons/reorder", [
                'lesson_ids' => [$lessons[2]->id, $lessons[0]->id, $lessons[1]->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $lessons[2]->id)
            ->assertJsonPath('data.2.id', $lessons[1]->id);

        $this->assertSame(0, $lessons[2]->fresh()->sort_order);
        $this->assertSame(1, $lessons[0]->fresh()->sort_order);
        $this->assertSame(2, $lessons[1]->fresh()->sort_order);
    }

    public function test_a_lesson_from_another_section_is_rejected(): void
    {
        // The realistic smuggle here: a lesson of the SAME company, so
        // TenantScope lets it through and only the parent check catches it.
        [$company, , $module, $lessons] = $this->makeSection([[], []]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $otherModule = Module::factory()->for($company)->create(['cert_tier_id' => $module->cert_tier_id]);
        $outsider = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $otherModule->id,
            'sort_order' => 0,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/modules/{$module->id}/lessons/reorder", [
                'lesson_ids' => [$lessons[1]->id, $lessons[0]->id, $outsider->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lesson_ids');

        $this->assertSame(0, $lessons[0]->fresh()->sort_order);
        $this->assertSame(1, $lessons[1]->fresh()->sort_order);
        $this->assertSame(0, $outsider->fresh()->sort_order);
    }

    public function test_another_companys_section_is_not_reorderable_at_all(): void
    {
        [, , $module, $lessons] = $this->makeSection([[]]);

        $outsiderAdmin = User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        // BR-6 — TenantScope makes the Section absent at route-model
        // binding, so this is a 404 before the handler runs.
        $this->actingAs($outsiderAdmin)
            ->putJson("/api/v1/modules/{$module->id}/lessons/reorder", ['lesson_ids' => [$lessons[0]->id]])
            ->assertNotFound();
    }

    public function test_an_agent_cannot_reorder_lessons(): void
    {
        [, $agent, $module, $lessons] = $this->makeSection([[]]);

        $this->actingAs($agent)
            ->putJson("/api/v1/modules/{$module->id}/lessons/reorder", ['lesson_ids' => [$lessons[0]->id]])
            ->assertForbidden();
    }

    // =================================================================
    // §2.2 — SEQUENTIAL UNLOCK, ENFORCED ON ALL THREE ROUTES
    // =================================================================

    public function test_a_sequential_section_locks_the_second_lesson_until_the_first_is_complete(): void
    {
        Storage::fake('local');
        [, $agent, $module] = $this->makeSection([], ['enforce_sequential' => true]);

        $first = $this->makeUploadedLesson($module, 0, ['duration_seconds' => 600]);
        $second = $this->makeUploadedLesson($module, 1, ['duration_seconds' => 600]);

        // 1. STREAM — refused before any bytes (§2.2).
        $this->actingAs($agent)
            ->get("/api/v1/module-lessons/{$second->id}/stream")
            ->assertForbidden();

        // 2. PROGRESS PUT — refused, so a caller cannot bank the max_* that
        //    would satisfy ADR-028's gate the moment the lock lifts.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$second->id}/progress", ['last_position_seconds' => 600])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_lesson_id');

        $this->assertSame(0, ModuleLessonProgress::withoutGlobalScopes()->count());

        // 3. COMPLETION POST — refused.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $second->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_lesson_id');

        $this->assertSame(0, ModuleCompletion::withoutGlobalScopes()->count());

        // The FIRST lesson is open throughout — a sequential Section must
        // still be enterable.
        $this->actingAs($agent)->get("/api/v1/module-lessons/{$first->id}/stream")->assertOk();
    }

    public function test_completing_the_previous_lesson_unlocks_all_three_routes(): void
    {
        Storage::fake('local');
        [, $agent, $module] = $this->makeSection([], ['enforce_sequential' => true]);

        $first = $this->makeUploadedLesson($module, 0, ['duration_seconds' => 600]);
        $second = $this->makeUploadedLesson($module, 1, ['duration_seconds' => 600]);

        $this->complete($agent, $first);

        $this->actingAs($agent)->get("/api/v1/module-lessons/{$second->id}/stream")->assertOk();

        // TASK-165 §3.4 — 200 with `{"completed": ...}` rather than 204,
        // and §3.2 means watching all 600s of an UPLOADED video records the
        // completion here, so the POST below finds the existing row (200).
        // The subject of this test is that all three routes are OPEN once
        // the predecessor is complete, which is unchanged.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$second->id}/progress", ['last_position_seconds' => 600])
            ->assertOk()
            ->assertJsonPath('completed', true);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $second->id])
            ->assertOk();
    }

    public function test_the_lock_names_the_reason_and_leaks_no_measurement(): void
    {
        [, $agent, $module, $lessons] = $this->makeSection(
            [[], []],
            ['enforce_sequential' => true],
        );

        $response = $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[1]->id])
            ->assertUnprocessable();

        // ADR-031 §3 — the learner must be told WHICH problem this is.
        $this->assertSame(
            'ต้องเรียนบทก่อนหน้าให้จบก่อน จึงจะเข้าเรียนบทนี้ได้',
            $response->json('errors.module_lesson_id.0'),
        );

        // ADR-028 §4 still holds: the refusal names the ACTION and carries
        // no measurement — no percentage, no threshold, no "you are 2 of 3
        // lessons away". Decoded and re-encoded unescaped, because Laravel
        // escapes Thai to \uXXXX and a raw-string scan would check the wrong
        // thing.
        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('%', $payload);
        $this->assertStringNotContainsString('percent', $payload);
    }

    public function test_the_resource_exposes_the_lock_and_its_reason(): void
    {
        [, $agent, $module, $lessons] = $this->makeSection(
            [[], []],
            ['enforce_sequential' => true],
        );

        $data = $this->actingAs($agent)
            ->getJson("/api/v1/modules/{$module->id}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['lessons'][0]['is_locked']);
        $this->assertNull($data['lessons'][0]['lock_reason']);

        $this->assertTrue($data['lessons'][1]['is_locked']);
        $this->assertSame('sequential_previous', $data['lessons'][1]['lock_reason']);
        $this->assertNotNull($data['lessons'][1]['lock_message']);
        // Not a drip lock, so no date to wait for.
        $this->assertNull($data['lessons'][1]['unlocks_at']);
    }

    public function test_an_admin_is_never_locked_by_sequential_unlock(): void
    {
        Storage::fake('local');
        [$company, , $module] = $this->makeSection([], ['enforce_sequential' => true]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->makeUploadedLesson($module, 0);
        $second = $this->makeUploadedLesson($module, 1);

        // They are authoring and previewing, not learning — the same
        // exemption ModuleLessonResource already makes for quiz_unlocked.
        $this->actingAs($admin)->get("/api/v1/module-lessons/{$second->id}/stream")->assertOk();
    }

    public function test_an_unpublished_lesson_never_becomes_an_invisible_permanent_lock(): void
    {
        [, $agent, $module, $lessons] = $this->makeSection(
            [[], ['is_published' => false], []],
            ['enforce_sequential' => true],
        );

        $this->complete($agent, $lessons[0]);

        // The middle lesson is a draft the learner cannot see, so it cannot
        // be completed — leaving it in the chain would strand everyone
        // behind it with no cause visible in the UI.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[2]->id])
            ->assertCreated();
    }

    public function test_a_locked_lessons_quiz_cannot_be_attempted(): void
    {
        // Not named in ADR-031 (which lists stream/completion/progress) but
        // the same hole: a lesson with no verifiable content satisfies
        // ADR-028's content gate trivially, so without this check a learner
        // could pass — and record — the quiz of a lesson they may not open.
        [$company, $agent, , $lessons] = $this->makeSection(
            [[], []],
            ['enforce_sequential' => true],
        );

        $quiz = Quiz::create(['company_id' => $company->id, 'title' => 'q']);
        $question = ModuleLessonQuizQuestion::create([
            'company_id' => $company->id,
            'quiz_id' => $quiz->id,
            'question_text' => 'Q1?',
            'sort_order' => 0,
        ]);
        $option = ModuleLessonQuizOption::create([
            'company_id' => $company->id,
            'module_lesson_quiz_question_id' => $question->id,
            'option_text' => 'A',
            'is_correct' => true,
            'sort_order' => 0,
        ]);
        // quiz_id is not fillable by design (ADR-030 §2.1) — the link moves
        // only through QuizService in production.
        $lessons[1]->forceFill(['quiz_id' => $quiz->id])->save();

        // A payload that WOULD grade cleanly if the lesson were open, so a
        // 422 here can only come from the ADR-031 lock and not from the
        // Form Request.
        $response = $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lessons[1]->id}/quiz-attempts", [
                'answers' => [$question->id => $option->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_lesson_id');

        $this->assertSame(
            'ต้องเรียนบทก่อนหน้าให้จบก่อน จึงจะเข้าเรียนบทนี้ได้',
            $response->json('errors.module_lesson_id.0'),
        );

        $this->assertSame(0, ModuleLessonQuizAttempt::withoutGlobalScopes()->count());
    }

    // =================================================================
    // §2.4 — OPTIONAL LESSONS NEVER BLOCK THE CHAIN
    // =================================================================

    public function test_an_optional_lesson_between_two_required_ones_does_not_gate_the_next(): void
    {
        [, $agent, , $lessons] = $this->makeSection(
            [
                [],                        // 0: required
                ['is_optional' => true],   // 1: optional — must not gate
                [],                        // 2: required
            ],
            ['enforce_sequential' => true],
        );

        // Finish only the first REQUIRED lesson. The optional one is skipped
        // entirely, which is what "optional" has to mean.
        $this->complete($agent, $lessons[0]);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[2]->id])
            ->assertCreated();
    }

    public function test_a_required_lesson_still_gates_the_optional_one_after_it(): void
    {
        [, $agent, , $lessons] = $this->makeSection(
            [[], ['is_optional' => true]],
            ['enforce_sequential' => true],
        );

        // "Optional" means not required OF the learner; it does not mean the
        // course order stops applying to it.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[1]->id])
            ->assertUnprocessable();

        $this->complete($agent, $lessons[0]);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[1]->id])
            ->assertCreated();
    }

    public function test_optional_lessons_are_excluded_from_the_required_lesson_count(): void
    {
        [, $agent, $module] = $this->makeSection([
            [],
            [],
            ['is_optional' => true],
            ['is_published' => false],
        ]);

        $data = $this->actingAs($agent)
            ->getJson("/api/v1/modules/{$module->id}")
            ->assertOk()
            ->json('data');

        // The learner's denominator: published AND required. Counting the
        // optional lesson would leave a learner at "2/3" forever (§2.4);
        // counting the draft would leave them at "2/3" too, for a lesson
        // they cannot even see.
        $this->assertSame(2, $data['required_lesson_count']);
        $this->assertSame(1, $data['optional_lesson_count']);

        /*
         * TASK-155 — THIS ASSERTION USED TO READ 4, AND THAT WAS THE BUG.
         *
         * `lesson_count` is the ADMIN's total ("unchanged in meaning from
         * lessons.length"), but it was being asserted from an AGENT's request,
         * and it passed because GET /modules served every lesson to everyone,
         * drafts included. The Vue client hid them; the API did not. So the 4
         * here was encoding the leak, not the rule.
         *
         * An Agent now receives 3 — the draft is not in their payload at all.
         * Asserted from both roles so the two answers stay deliberately
         * different rather than drifting back together.
         */
        $this->assertSame(3, $data['lesson_count']);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $module->company_id]);

        $adminData = $this->actingAs($admin)
            ->getJson("/api/v1/modules/{$module->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(4, $adminData['lesson_count']);
    }

    // =================================================================
    // §2.3 — DRIP, AT THE BOUNDARY
    // =================================================================

    public function test_a_dripped_section_is_locked_until_the_anchor_plus_n_days(): void
    {
        [, $agent, , $lessons] = $this->makeSection([[]], ['drip_days' => 3]);

        // 30 seconds SHORT of three days after approval — still locked.
        $agent->forceFill(['approved_at' => now()->subDays(3)->addSeconds(30)])->save();

        $response = $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[0]->id])
            ->assertUnprocessable();

        $this->assertSame(
            'บทเรียนนี้ยังไม่เปิดให้เรียน กรุณารอถึงวันที่กำหนด',
            $response->json('errors.module_lesson_id.0'),
        );

        // 30 seconds PAST it — open.
        $agent->forceFill(['approved_at' => now()->subDays(3)->subSeconds(30)])->save();

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[0]->id])
            ->assertCreated();
    }

    public function test_the_drip_anchor_falls_back_to_created_at_when_never_approved(): void
    {
        // ADR-031 §2.3 — accounts that predate the approvals feature have no
        // approved_at, and a null anchor must not mean a permanent lock.
        [, $agent, , $lessons] = $this->makeSection([[]], ['drip_days' => 1]);

        $agent->forceFill(['approved_at' => null])->save();
        // created_at is "now" from the factory, so a 1-day drip is closed.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[0]->id])
            ->assertUnprocessable();

        $agent->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[0]->id])
            ->assertCreated();
    }

    public function test_drip_exposes_when_the_section_opens_so_the_ui_can_say_how_long(): void
    {
        [, $agent, $module, $lessons] = $this->makeSection([[]], ['drip_days' => 3]);

        $agent->forceFill(['approved_at' => now()->subDay()])->save();

        $data = $this->actingAs($agent)
            ->getJson("/api/v1/modules/{$module->id}")
            ->assertOk()
            ->json('data');

        // ADR-031 §3 — "จะเปิดในอีก 3 วัน" is a different problem from
        // "finish the previous lesson", so the UI gets the date, not just a
        // padlock.
        $this->assertSame(3, $data['drip_days']);
        $this->assertNotNull($data['unlocks_at']);
        $this->assertSame('drip', $data['lessons'][0]['lock_reason']);
        $this->assertNotNull($data['lessons'][0]['unlocks_at']);
        $this->assertTrue($data['lessons'][0]['is_locked']);
        $this->assertSame($lessons[0]->id, $data['lessons'][0]['id']);
    }

    public function test_drip_and_sequential_compose_with_drip_reported_first(): void
    {
        // §2.3 — "a Section can be both dripped and sequential". Drip wins
        // the REASON because telling a learner to finish the previous lesson
        // would send them somewhere they also cannot go.
        [, $agent, , $lessons] = $this->makeSection(
            [[], []],
            ['drip_days' => 5, 'enforce_sequential' => true],
        );

        $agent->forceFill(['approved_at' => now()])->save();

        // lessons[1] would be locked by EITHER rule on its own. The reported
        // reason must be the drip one.
        $response = $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[1]->id])
            ->assertUnprocessable();

        $this->assertSame(
            'บทเรียนนี้ยังไม่เปิดให้เรียน กรุณารอถึงวันที่กำหนด',
            $response->json('errors.module_lesson_id.0'),
        );

        // Once the drip opens, the sequential lock is what remains.
        $agent->forceFill(['approved_at' => now()->subDays(6)])->save();

        $second = $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[1]->id])
            ->assertUnprocessable();

        $this->assertSame(
            'ต้องเรียนบทก่อนหน้าให้จบก่อน จึงจะเข้าเรียนบทนี้ได้',
            $second->json('errors.module_lesson_id.0'),
        );
    }

    // =================================================================
    // NON-NEGOTIABLE 3 — the ADR-028 admin override is the release valve
    // ADR-031 §2.2 depends on ("one lesson whose content is broken blocks
    // everyone behind it").
    // =================================================================

    public function test_an_admin_override_still_works_through_a_sequential_lock(): void
    {
        [$company, $agent, , $lessons] = $this->makeSection(
            [[], []],
            ['enforce_sequential' => true],
        );
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // The learner is stranded on lesson 1 (say its file will not render).
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[1]->id])
            ->assertUnprocessable();

        // The admin completes the BROKEN lesson on their behalf...
        $this->actingAs($admin)
            ->postJson("/api/v1/module-lessons/{$lessons[0]->id}/completions/override", ['user_id' => $agent->id])
            ->assertCreated();

        $this->assertSame(1, AuditLog::where('action', 'module_completion.admin_override')->count());

        // ...which is what unsticks the chain, because the chain reads
        // module_completions and the override writes one.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[1]->id])
            ->assertCreated();
    }

    public function test_an_admin_can_override_a_locked_lesson_directly(): void
    {
        [$company, $agent, , $lessons] = $this->makeSection(
            [[], []],
            ['enforce_sequential' => true, 'drip_days' => 30],
        );
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent->forceFill(['approved_at' => now()])->save();

        // Both locks on at once, and the override still lands: this path
        // never consults LessonAccessGate at all, by design.
        $this->actingAs($admin)
            ->postJson("/api/v1/module-lessons/{$lessons[1]->id}/completions/override", ['user_id' => $agent->id])
            ->assertCreated();

        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('module_lesson_id', $lessons[1]->id)
            ->count());
    }

    // =================================================================
    // NON-NEGOTIABLE 1 — NOTHING CHANGES FOR EXISTING DATA
    //
    // A fixture Section with NEITHER flag set: exactly what every Section
    // in production reads back as the moment the migration runs.
    // =================================================================

    public function test_a_section_with_neither_flag_set_behaves_exactly_as_before(): void
    {
        Storage::fake('local');
        [, $agent, $module] = $this->makeSection();

        $this->assertFalse((bool) $module->enforce_sequential, 'the migration default must be off');
        $this->assertNull($module->drip_days, 'the migration default must be null');

        $first = $this->makeUploadedLesson($module, 0, ['duration_seconds' => 600]);
        $second = $this->makeUploadedLesson($module, 1, ['duration_seconds' => 600]);
        $third = $this->makeUploadedLesson($module, 2, ['duration_seconds' => 600]);

        // Open the LAST lesson first, having touched nothing before it —
        // the pre-ADR-031 behaviour, and the thing sequential unlock would
        // forbid if it were somehow on.
        $this->actingAs($agent)->get("/api/v1/module-lessons/{$third->id}/stream")->assertOk();

        // TASK-165 §3.4/§3.2 — 200 with a body now, and watching an
        // UPLOADED video to the end records the completion. Neither is an
        // ADR-031 behaviour: this test is about an untouched Section still
        // letting a learner open the LAST lesson first, which it does.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$third->id}/progress", ['last_position_seconds' => 600])
            ->assertOk();

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $third->id])
            ->assertOk();

        // ...and the middle one, still out of order.
        $this->actingAs($agent)->get("/api/v1/module-lessons/{$second->id}/stream")->assertOk();
        $this->actingAs($agent)->get("/api/v1/module-lessons/{$first->id}/stream")->assertOk();
    }

    public function test_the_new_resource_fields_are_inert_on_an_untouched_section(): void
    {
        [, $agent, $module, $lessons] = $this->makeSection([[], []]);

        $data = $this->actingAs($agent)
            ->getJson("/api/v1/modules/{$module->id}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['enforce_sequential']);
        $this->assertNull($data['drip_days']);
        $this->assertNull($data['unlocks_at']);
        $this->assertSame(2, $data['required_lesson_count']);
        $this->assertSame(0, $data['optional_lesson_count']);

        foreach ($data['lessons'] as $lesson) {
            $this->assertFalse($lesson['is_locked']);
            $this->assertNull($lesson['lock_reason']);
            $this->assertNull($lesson['lock_message']);
            $this->assertNull($lesson['unlocks_at']);
            $this->assertFalse($lesson['is_optional']);
        }

        $this->assertCount(2, $lessons);
    }

    // =================================================================
    // NON-NEGOTIABLE 2 — the ADR-028 / ADR-029 gates still work
    // =================================================================

    public function test_the_adr_028_content_gate_still_blocks_an_unwatched_but_unlocked_lesson(): void
    {
        Storage::fake('local');
        [, $agent, $module] = $this->makeSection([], ['enforce_sequential' => true]);

        $only = $this->makeUploadedLesson($module, 0, ['duration_seconds' => 600]);

        // First in a sequential Section, so ADR-031 does not lock it — and
        // ADR-028 must still refuse the completion, with ITS message.
        $response = $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $only->id])
            ->assertUnprocessable();

        $this->assertSame(
            'กรุณาดูวิดีโอให้ครบก่อนจึงจะกดเรียนจบได้',
            $response->json('errors.module_lesson_id.0'),
        );

        // TASK-165 §3.2 — the PUT records the completion itself now, so the
        // POST that follows answers 200. What this test asserts is that the
        // ADR-028 gate REFUSED the completion before the video was watched
        // (above) and permits it after, which both statuses still show.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$only->id}/progress", ['last_position_seconds' => 600])
            ->assertOk()
            ->assertJsonPath('completed', true);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $only->id])
            ->assertOk();
    }

    public function test_a_completion_earned_before_the_switch_was_flipped_is_never_re_evaluated(): void
    {
        // The same grandfathering guarantee ADR-028 §2.3 and ADR-029 §3 give,
        // now extended to ADR-031: turning `enforce_sequential` on today must
        // not make yesterday's completions unreachable.
        [, $agent, $module, $lessons] = $this->makeSection([[], []]);

        $this->complete($agent, $lessons[1]);

        $module->update(['enforce_sequential' => true]);

        // Lesson 1 is now locked (lesson 0 is unfinished), but the completion
        // that already exists is still listed and a repeat POST is still the
        // no-op it always was — 200, not 422.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lessons[1]->id])
            ->assertOk();

        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()->count());
    }
}
