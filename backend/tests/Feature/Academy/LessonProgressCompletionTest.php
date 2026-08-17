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
use App\Models\ModuleLessonProgress;
use App\Models\User;
use App\Models\XpLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-146 / ADR-028 §2.3, §4 — completion is EARNED, not asserted.
 *
 * The two non-negotiables have their own sections at the bottom:
 * grandfathering (an existing completion is never re-evaluated) and
 * "XP exactly once per lesson".
 *
 * ── TASK-165 changed two expectations throughout this file ──────────
 *
 * 1. `PUT .../progress` answers `{"completed": bool}` (200) instead of
 *    204 No Content (§3.4), so the row can flip without polling.
 *    `assertNoContent()` became `assertOk()`. The ADR-028 §4 guarantee it
 *    was protecting — no measurement in the response — is now asserted
 *    positively, as an exact key set, in
 *    AutomaticLessonCompletionTest::test_the_progress_response_is_one_boolean_and_leaks_no_measurement.
 *
 * 2. Where a test reports progress PAST the threshold and then POSTs
 *    /module-completions, that POST no longer answers 201. It cannot: §3.2
 *    means the PUT itself already recorded the completion, so the POST
 *    finds the existing row and JsonResource::calculateStatus() answers
 *    200 — the same 200 a repeat POST has always returned. `assertCreated()`
 *    became `assertOk()` plus an explicit assertion that the completion
 *    exists, which is what these tests were really about.
 */
class LessonProgressCompletionTest extends TestCase
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
     * @return array{0: Company, 1: User, 2: ModuleLesson}
     */
    private function makeUploadedLesson(array $attributes): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

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

    // =================================================================
    // Progress recording — monotonic max + clamping (ADR-028 §2.3)
    // =================================================================

    public function test_scrubbing_a_video_backwards_never_lowers_the_max(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $url = "/api/v1/module-lessons/{$lesson->id}/progress";

        // THE classic bug in this feature (ADR-028 §2.3). Watch to 8:20,
        // then scrub back to the start: last_* follows, max_* must not.
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 500])->assertOk();
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 12])->assertOk();

        $progress = ModuleLessonProgress::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(12, $progress->last_position_seconds, 'resume position follows the learner backwards');
        $this->assertSame(500, $progress->max_position_seconds, 'earned progress must never be un-earned');
    }

    public function test_paging_back_through_a_pdf_never_lowers_the_max_page(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'pdf']);
        $url = "/api/v1/module-lessons/{$lesson->id}/progress";

        $this->actingAs($agent)->putJson($url, ['total_pages' => 12, 'last_page' => 12])->assertOk();
        $this->actingAs($agent)->putJson($url, ['last_page' => 2])->assertOk();

        $progress = ModuleLessonProgress::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(2, $progress->last_page);
        $this->assertSame(12, $progress->max_page);
    }

    public function test_a_forged_position_beyond_the_videos_duration_is_clamped_not_accepted(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 60]);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 9999])
            ->assertOk();

        $progress = ModuleLessonProgress::withoutGlobalScopes()->firstOrFail();

        // ADR-028 §3 — the client reports positions; the server decides
        // what they mean.
        $this->assertSame(60, $progress->last_position_seconds);
        $this->assertSame(60, $progress->max_position_seconds);
    }

    public function test_a_forged_page_beyond_the_document_is_clamped(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'pdf']);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['total_pages' => 5, 'last_page' => 900])
            ->assertOk();

        $progress = ModuleLessonProgress::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(5, $progress->max_page);
    }

    public function test_a_shrunken_total_pages_report_is_ignored(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'pdf']);
        $url = "/api/v1/module-lessons/{$lesson->id}/progress";

        $this->actingAs($agent)->putJson($url, ['total_pages' => 40, 'last_page' => 1])->assertOk();
        // Shrinking the denominator is how a forger would reach 100% early.
        $this->actingAs($agent)->putJson($url, ['total_pages' => 1, 'last_page' => 1])->assertOk();

        $this->assertSame(40, ModuleLessonProgress::withoutGlobalScopes()->firstOrFail()->total_pages);
    }

    public function test_the_progress_response_never_leaks_the_recorded_numbers_to_the_learner(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        /*
         * ADR-028 §4 — the learner is not told how far they got.
         *
         * This used to be "204, therefore nothing can leak", which was the
         * cheapest way to hold the guarantee. TASK-165 §3.4 needed ONE bit
         * back (whether they are now complete — which is not a measurement,
         * and which GET /module-completions has always told them), so the
         * assertion becomes an exact key set instead of an empty body. That
         * is strictly more specific than the old one: a future field would
         * fail this test whether or not it carried a number, exactly as an
         * empty-body assertion would have.
         */
        $response = $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 100]);

        $response->assertOk();
        $this->assertSame(['completed'], array_keys($response->json()));
        $this->assertFalse($response->json('completed'));
    }

    public function test_page_fields_are_rejected_on_a_video_lesson(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_page' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('last_page');
    }

    public function test_an_empty_progress_report_is_rejected(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", [])
            ->assertUnprocessable();
    }

    public function test_another_companys_agent_cannot_write_progress_on_a_lesson(): void
    {
        Storage::fake('local');
        [, , $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $outsider = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        // BR-6 / §5 rule 5 — TenantScope makes the id simply absent.
        $this->actingAs($outsider)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 10])
            ->assertNotFound();

        $this->assertSame(0, ModuleLessonProgress::withoutGlobalScopes()->count());
    }

    public function test_progress_writing_requires_authentication(): void
    {
        Storage::fake('local');
        [, , $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $this->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 10])
            ->assertUnauthorized();
    }

    // =================================================================
    // The gate (ADR-028 §2.3)
    // =================================================================

    public function test_completing_a_video_lesson_without_watching_it_is_rejected(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        // The entire point of the sprint: prove it with a raw POST, not
        // through the UI (TASK-148).
        $response = $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('module_lesson_id');

        $this->assertSame(0, ModuleCompletion::withoutGlobalScopes()->count());

        // ADR-028 §4 — actionable, never specific.
        $this->assertSame(
            'กรุณาดูวิดีโอให้ครบก่อนจึงจะกดเรียนจบได้',
            $response->json('errors.module_lesson_id.0'),
        );

        // ...and no percentage, threshold or second count anywhere ELSE in
        // the payload either. Decoded and re-encoded unescaped, because
        // Laravel's JsonResponse escapes Thai to \uXXXX by default and a
        // raw-string scan would be checking the wrong thing.
        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('80', $payload);
        $this->assertStringNotContainsString('600', $payload);
        $this->assertStringNotContainsString('%', $payload);
    }

    public function test_watching_past_the_configured_threshold_earns_the_completion(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        // Default 80% of 600s = 480s (ADR-028 §4, human-stated).
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 480]);

        // TASK-165 §3.2 — 200, not 201: the PUT above has ALREADY recorded
        // the completion, so this POST returns the existing row. The
        // subject of this test is the gate ("watching enough earns it"),
        // and the row assertion states that directly rather than through a
        // status code that now says something else.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertOk();

        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('module_lesson_id', $lesson->id)
            ->count());
    }

    public function test_the_gate_reads_max_position_not_last_position(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $url = "/api/v1/module-lessons/{$lesson->id}/progress";

        // Watch it all, then scrub back to the beginning before clicking
        // "finished". The learner has still watched it.
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 600]);
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 3]);

        // TASK-165 §3.2 — 200: the first PUT already recorded it.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertOk();

        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()->count());
    }

    public function test_a_company_can_lower_its_own_video_threshold(): void
    {
        Storage::fake('local');
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        // BR-7 — the threshold is config, not a constant.
        AcademyCompletionSetting::create([
            'company_id' => $company->id,
            'video_watch_percent' => 50,
            'pdf_read_percent' => 100,
        ]);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 300])
            // TASK-165 §3.2 — the lowered threshold is met, so the PUT
            // itself records it. That IS this test's subject now.
            ->assertJsonPath('completed', true);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertOk();
    }

    public function test_a_pdf_lesson_must_be_read_to_the_end_by_default(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'pdf']);
        $url = "/api/v1/module-lessons/{$lesson->id}/progress";

        $this->actingAs($agent)->putJson($url, ['total_pages' => 10, 'last_page' => 9]);

        $blocked = $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable();

        $this->assertSame(
            'กรุณาอ่านเอกสารให้ครบก่อนจึงจะกดเรียนจบได้',
            $blocked->json('errors.module_lesson_id.0'),
        );

        // TASK-165 §3.2 — reaching the last page records it, with no POST.
        $this->actingAs($agent)->putJson($url, ['last_page' => 10])->assertJsonPath('completed', true);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertOk();
    }

    public function test_the_server_measured_page_count_beats_a_forged_total_pages(): void
    {
        Storage::fake('local');
        // page_count is what ModuleLessonService writes from pdfinfo at
        // upload time; the client never supplies it.
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'pdf', 'page_count' => 50]);
        $url = "/api/v1/module-lessons/{$lesson->id}/progress";

        // The forgery: "this document only has 1 page, and I'm on it."
        // TASK-165 §3.2 — and it buys no AUTOMATIC completion either, which
        // is the sharper form of the refusal asserted just below.
        $this->actingAs($agent)->putJson($url, ['total_pages' => 1, 'last_page' => 1])
            ->assertOk()
            ->assertJsonPath('completed', false);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable();

        // Reaching the real last page does earn it — and now records it.
        $this->actingAs($agent)->putJson($url, ['last_page' => 50])
            ->assertOk()
            ->assertJsonPath('completed', true);
        $this->assertSame(50, ModuleLessonProgress::withoutGlobalScopes()->firstOrFail()->max_page);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertOk();
    }

    public function test_a_pdf_that_was_never_opened_fails_closed(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'pdf']);

        // No progress row at all — the reader reports total_pages on open,
        // so this means it was never opened.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable();
    }

    public function test_a_downloadable_lesson_falls_back_to_the_button(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson([
            'content_type' => 'pdf',
            'is_downloadable' => true,
        ]);

        // ADR-028 §2.3, stated openly: a file the learner may keep can be
        // read outside the app, so in-app position measures nothing.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    public function test_an_embedded_video_falls_back_to_the_button(): void
    {
        Storage::fake('local');
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

        // We receive no position events from somebody else's player, so
        // blocking on evidence we can never get would lock the learner out.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    public function test_an_uploaded_video_with_no_known_duration_falls_back_to_the_button(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson([
            'content_type' => 'video',
            'duration_seconds' => null,
        ]);

        // DELIBERATE FAIL-OPEN (LessonCompletionGate::videoEarned): with no
        // ffprobe there is no honest denominator, and failing closed would
        // block the BR-1 certification path for a whole company because of
        // our own infrastructure gap (ADR-028 §5 R1).
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertCreated();
    }

    // =================================================================
    // NON-NEGOTIABLE 1 — grandfathering (ADR-028 §2.3 guard 1)
    // =================================================================

    public function test_a_pre_existing_completion_that_would_fail_todays_rule_survives(): void
    {
        Storage::fake('local');
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        // A completion earned under the OLD rule (a button press), with no
        // progress row behind it — exactly what the new gate would reject.
        $existing = ModuleCompletion::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'module_lesson_id' => $lesson->id,
            'completed_at' => now()->subMonths(3),
        ]);

        // It is still listed...
        $this->actingAs($agent)
            ->getJson('/api/v1/module-completions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // ...and a repeat POST is still the no-op it always was, NOT a
        // re-evaluation. Nobody loses a certification because we changed
        // the rule afterwards.
        //
        // 200, not 201: JsonResource::calculateStatus() only answers 201
        // for a model that was recently created, and this one was not.
        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertOk()
            ->assertJsonPath('data.id', $existing->id);

        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()->count());
        $this->assertTrue($existing->fresh()->exists);
    }

    // =================================================================
    // NON-NEGOTIABLE 2 — XP exactly once per lesson (BR-5)
    // =================================================================

    public function test_xp_is_awarded_exactly_once_per_lesson_on_first_completion(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 600]);

        // TASK-165 §3.2 — the PUT above already created the row, so EVERY
        // POST here returns the existing one (200). The subject of this
        // test is the XP count at the bottom, and it is now proving
        // something stronger: the automatic path and three manual POSTs
        // between them award exactly one.
        $this->actingAs($agent)->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])->assertOk();
        $this->actingAs($agent)->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])->assertOk();
        $this->actingAs($agent)->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])->assertOk();

        $this->assertSame(1, XpLedger::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('source_type', GamificationSourceType::ModuleCompleted)
            ->count());
    }

    public function test_a_rejected_completion_awards_no_xp(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertUnprocessable();

        $this->assertSame(0, XpLedger::withoutGlobalScopes()->count());
    }

    // =================================================================
    // NON-NEGOTIABLE 3 — an ADMIN OVERRIDE awards NO XP (ADR-028 §4.1)
    // =================================================================

    public function test_an_admin_override_writes_the_completion_but_awards_no_xp(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/completions/override", ['user_id' => $agent->id])
            ->assertCreated();

        // The learner IS credited with the lesson (it feeds the BR-1 gate)...
        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('module_lesson_id', $lesson->id)
            ->count());

        // ...and audit-logged, exactly as before.
        $this->assertSame(1, AuditLog::where('action', 'module_completion.admin_override')->count());

        // ADR-028 §4.1 — but NOT with the effort. XP rewards learning
        // behaviour (BR-5 source (a)); an override records that we are
        // accepting the lesson as done for an operational reason. XP is not
        // inert — it feeds levels, badges, the leaderboard and the
        // promotion bonuses that pay real money (TASK-042) — so awarding it
        // here would create a standing incentive to request an override.
        $this->assertSame(0, XpLedger::withoutGlobalScopes()->count(), 'an override must never award XP');
    }

    public function test_an_override_and_nothing_else_leaves_the_xp_ledger_completely_untouched(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'pdf']);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $url = "/api/v1/module-lessons/{$lesson->id}/completions/override";
        $this->actingAs($admin)->postJson($url, ['user_id' => $agent->id])->assertCreated();
        // Idempotent repeat — must not find a back door on the second call.
        $this->actingAs($admin)->postJson($url, ['user_id' => $agent->id])->assertOk();

        // Deliberately unfiltered: the WHOLE ledger, for anyone, is empty.
        $this->assertSame(0, XpLedger::withoutGlobalScopes()->count());
    }

    public function test_a_normal_completion_after_an_override_still_awards_nothing(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/completions/override", ['user_id' => $agent->id])
            ->assertCreated();

        // The agent later genuinely watches the whole thing and presses
        // "finished". There is no second FIRST completion to reward — the
        // POST returns the existing row (200), and the ledger stays empty.
        // Closing the obvious farm: override, then re-POST for the XP.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 600]);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertOk();

        $this->assertSame(0, XpLedger::withoutGlobalScopes()->count());
        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()->count());
    }

    public function test_the_normal_path_still_awards_xp_once_when_no_override_is_involved(): void
    {
        Storage::fake('local');
        $this->seedModuleCompletedXpRule(10);
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        // The regression guard for the change above: withholding XP on the
        // OVERRIDE must not have withheld it on the EARNED path, which is
        // the path BR-5 source (a) is actually about. The sibling
        // "exactly once" test counts rows; this one checks the row that
        // exists carries the configured value, so a change that reached
        // awardXp() with a zeroed amount would still fail here.
        // TASK-165 §3.2 — the PUT is what records it now, so the POST that
        // follows answers 200. The XP assertions below are unchanged and
        // are what this test is for.
        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 600]);

        $this->actingAs($agent)
            ->postJson('/api/v1/module-completions', ['module_lesson_id' => $lesson->id])
            ->assertOk();

        $ledger = XpLedger::withoutGlobalScopes()
            ->where('user_id', $agent->id)
            ->where('source_type', GamificationSourceType::ModuleCompleted)
            ->get();

        $this->assertCount(1, $ledger);
        $this->assertSame(10, (int) $ledger->first()->xp_awarded);
    }

    // =================================================================
    // Admin override (ADR-028 §2.3 guard 2) — audit-logged
    // =================================================================

    public function test_an_admin_can_override_the_gate_and_the_action_is_audit_logged(): void
    {
        Storage::fake('local');
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

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

    public function test_the_admin_override_is_idempotent(): void
    {
        Storage::fake('local');
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $url = "/api/v1/module-lessons/{$lesson->id}/completions/override";

        $this->actingAs($admin)->postJson($url, ['user_id' => $agent->id])->assertCreated();
        $this->actingAs($admin)->postJson($url, ['user_id' => $agent->id])->assertOk();

        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::where('action', 'module_completion.admin_override')->count());
    }

    public function test_an_agent_cannot_override_the_gate_for_themselves(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $this->actingAs($agent)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/completions/override", ['user_id' => $agent->id])
            ->assertForbidden();

        $this->assertSame(0, ModuleCompletion::withoutGlobalScopes()->count());
    }

    public function test_an_admin_cannot_override_for_an_agent_of_another_company(): void
    {
        Storage::fake('local');
        [$company, , $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $foreignAgent = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/module-lessons/{$lesson->id}/completions/override", ['user_id' => $foreignAgent->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    // =================================================================
    // Admin progress readout (ADR-028 §4)
    // =================================================================

    public function test_an_admin_can_read_the_recorded_progress_the_learner_is_not_shown(): void
    {
        Storage::fake('local');
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 372]);

        $this->actingAs($admin)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/progress")
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $agent->id)
            ->assertJsonPath('data.0.max_position_seconds', 372);
    }

    public function test_an_agent_cannot_read_the_admin_progress_readout(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $this->actingAs($agent)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/progress")
            ->assertForbidden();
    }

    // =================================================================
    // Learner-scoped resume read (ADR-028 §4.1)
    //
    // "A bookmark is not the withheld number." The learner may read WHERE
    // THEY STOPPED; they may never read how close that is to passing.
    // =================================================================

    public function test_a_learner_can_read_their_own_video_resume_position(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['last_position_seconds' => 372]);

        // TASK-147: close the app, reopen, resume. Before ADR-028 §4.1 the
        // only readout was Admin-scoped, so this died with the session.
        $this->actingAs($agent)
            ->getJson("/api/v1/me/module-lessons/{$lesson->id}/progress")
            ->assertOk()
            ->assertExactJson([
                'last_position_seconds' => 372,
                'last_page' => null,
            ]);
    }

    public function test_a_learner_can_read_their_own_pdf_resume_page(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'pdf']);

        $this->actingAs($agent)
            ->putJson("/api/v1/module-lessons/{$lesson->id}/progress", ['total_pages' => 40, 'last_page' => 17]);

        $this->actingAs($agent)
            ->getJson("/api/v1/me/module-lessons/{$lesson->id}/progress")
            ->assertOk()
            // total_pages is absent even though it was just reported BY
            // this learner: a denominator plus a max is the withheld
            // percentage rebuilt by hand on the client.
            ->assertExactJson([
                'last_position_seconds' => null,
                'last_page' => 17,
            ]);
    }

    public function test_the_resume_read_returns_nulls_rather_than_404_for_an_unopened_lesson(): void
    {
        Storage::fake('local');
        [, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        // A bookmark of "nowhere" is a legitimate answer, and a 404/200
        // split would report whether a progress row exists — one bit more
        // than this endpoint needs to give.
        $this->actingAs($agent)
            ->getJson("/api/v1/me/module-lessons/{$lesson->id}/progress")
            ->assertOk()
            ->assertExactJson([
                'last_position_seconds' => null,
                'last_page' => null,
            ]);
    }

    public function test_the_resume_read_leaks_no_max_no_threshold_and_no_percentage(): void
    {
        Storage::fake('local');
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        AcademyCompletionSetting::create([
            'company_id' => $company->id,
            'video_watch_percent' => 80,
            'pdf_read_percent' => 100,
        ]);

        $url = "/api/v1/module-lessons/{$lesson->id}/progress";
        // max_position_seconds becomes 540 (90% — past the 80% threshold),
        // last_position_seconds falls back to 61. If ANY max-derived value
        // leaked, 540 would be findable in the payload.
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 540]);
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 61]);

        $response = $this->actingAs($agent)
            ->getJson("/api/v1/me/module-lessons/{$lesson->id}/progress")
            ->assertOk();

        // The keys are the whole contract — asserted as an exact set, so a
        // future added field fails this test rather than shipping quietly.
        $this->assertSame(
            ['last_position_seconds', 'last_page'],
            array_keys($response->json()),
        );

        // ...and a raw-payload scan on top, the way the blocked-completion
        // non-leak test does it (unescaped, because Laravel escapes to
        // \uXXXX by default).
        $payload = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('max', $payload);
        $this->assertStringNotContainsString('540', $payload, 'max_position_seconds must not leak');
        $this->assertStringNotContainsString('600', $payload, 'duration_seconds must not leak');
        $this->assertStringNotContainsString('80', $payload, 'the configured threshold must not leak');
        $this->assertStringNotContainsString('total', $payload);
        $this->assertStringNotContainsString('percent', $payload);
        $this->assertStringNotContainsString('%', $payload);
    }

    public function test_reading_another_learners_bookmark_is_impossible_by_construction(): void
    {
        Storage::fake('local');
        [$company, $agent, $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);
        $colleague = User::factory()->agent()->create(['company_id' => $company->id]);

        $url = "/api/v1/module-lessons/{$lesson->id}/progress";
        $this->actingAs($agent)->putJson($url, ['last_position_seconds' => 111]);
        $this->actingAs($colleague)->putJson($url, ['last_position_seconds' => 222]);

        $meUrl = "/api/v1/me/module-lessons/{$lesson->id}/progress";

        // 1. The ROUTE ITSELF has no user parameter. This is the real
        //    guarantee: another learner's bookmark is not "forbidden", it
        //    is unrequestable — there is no input that could ask for it.
        //    Asserted structurally so that adding one breaks this test.
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/me/module-lessons/{moduleLesson}/progress');

        $this->assertNotNull($route, 'the learner-scoped resume route must exist');
        $this->assertSame(['moduleLesson'], $route->parameterNames());

        // 2. Same URL, two callers, two different answers — the row is
        //    resolved from the AUTHENTICATED user server-side.
        $this->actingAs($agent)->getJson($meUrl)->assertOk()->assertJsonPath('last_position_seconds', 111);
        $this->actingAs($colleague)->getJson($meUrl)->assertOk()->assertJsonPath('last_position_seconds', 222);

        // 3. And a smuggled identifier changes nothing — the query string
        //    is not consulted at all.
        $this->actingAs($agent)
            ->getJson($meUrl."?user_id={$colleague->id}")
            ->assertOk()
            ->assertJsonPath('last_position_seconds', 111);
    }

    public function test_another_companys_agent_cannot_read_a_lessons_bookmark(): void
    {
        Storage::fake('local');
        [, , $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $outsider = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        // BR-6 / §5 rule 5 — TenantScope makes the lesson id simply absent
        // at route-model binding, so this is a 404 before the handler runs.
        $this->actingAs($outsider)
            ->getJson("/api/v1/me/module-lessons/{$lesson->id}/progress")
            ->assertNotFound();
    }

    public function test_the_resume_read_requires_authentication(): void
    {
        Storage::fake('local');
        [, , $lesson] = $this->makeUploadedLesson(['content_type' => 'video', 'duration_seconds' => 600]);

        $this->getJson("/api/v1/me/module-lessons/{$lesson->id}/progress")
            ->assertUnauthorized();
    }

    // =================================================================
    // Thresholds are config, not constants (BR-7 / ADR-028 §4)
    // =================================================================

    public function test_an_admin_can_read_and_update_the_completion_thresholds(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // The seeded platform defaults are the human's stated values.
        $this->actingAs($admin)
            ->getJson('/api/v1/academy-completion-settings')
            ->assertOk()
            ->assertJsonPath('data.video_watch_percent', 80)
            ->assertJsonPath('data.pdf_read_percent', 100);

        $this->actingAs($admin)
            ->putJson('/api/v1/academy-completion-settings', [
                'video_watch_percent' => 90,
                'pdf_read_percent' => 75,
            ])
            ->assertOk()
            ->assertJsonPath('data.video_watch_percent', 90);
    }

    public function test_an_agent_cannot_read_the_completion_thresholds(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // These ARE the numbers ADR-028 §4 decided a learner is not told.
        $this->actingAs($agent)
            ->getJson('/api/v1/academy-completion-settings')
            ->assertForbidden();
    }

    public function test_a_zero_threshold_cannot_be_configured(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // 0% would silently turn the gate off for a whole company with no
        // audit trail; the sanctioned route is the per-learner override.
        $this->actingAs($admin)
            ->putJson('/api/v1/academy-completion-settings', [
                'video_watch_percent' => 0,
                'pdf_read_percent' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['video_watch_percent', 'pdf_read_percent']);
    }

    public function test_a_company_admin_cannot_write_another_companys_thresholds(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // BR-6 — a client-supplied company_id must never redirect the
        // write (the same IDOR that was fixed across the settings services
        // in task #431).
        $this->actingAs($admin)
            ->putJson('/api/v1/academy-completion-settings', [
                'company_id' => $other->id,
                'video_watch_percent' => 10,
                'pdf_read_percent' => 10,
            ])
            ->assertOk();

        $this->assertSame(0, AcademyCompletionSetting::where('company_id', $other->id)->count());
        $this->assertSame(10, AcademyCompletionSetting::where('company_id', $company->id)->firstOrFail()->video_watch_percent);
    }
}
