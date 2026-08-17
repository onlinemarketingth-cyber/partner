<?php

namespace Tests\Feature\Academy;

use App\Jobs\CompressUploadedVideo;
use App\Models\AuditLog;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleCompletion;
use App\Models\ModuleLesson;
use App\Models\ModuleLessonProgress;
use App\Models\Quiz;
use App\Models\User;
use App\Services\Academy\QuizService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-188 §6.D — a lesson's content_type can be changed after creation
 * (human decision D2, 2026-08-13), and each of the four consequences §6.D2
 * names has a test here because each one is a way a customer could otherwise
 * discover it: the stored file, learner progress, the attached quiz, and
 * `is_downloadable`.
 */
class ModuleLessonContentTypeChangeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: Module} */
    private function makeCompanyAdminModule(): array
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        return [$company, $admin, $module];
    }

    /**
     * An UPLOADED pdf lesson with a real file on the fake disk, a measured
     * page count, and `is_downloadable` on — i.e. every column a retype has to
     * deal with, set to a non-default value so an assertion that it changed
     * cannot pass by accident.
     *
     * @return array{0: Company, 1: User, 2: Module, 3: ModuleLesson}
     */
    private function makeStoredPdfLesson(bool $isDownloadable = true): array
    {
        [$company, $admin, $module] = $this->makeCompanyAdminModule();

        $path = "academy-lessons/{$company->id}/1/".Str::uuid()->toString().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 fake bytes');

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'pdf',
            'source_type' => 'upload',
            'content_ref' => $path,
            'page_count' => 40,
            'is_downloadable' => $isDownloadable,
        ]);

        return [$company, $admin, $module, $lesson];
    }

    // -----------------------------------------------------------------
    // The change itself (§6.D1)
    // -----------------------------------------------------------------

    public function test_a_lesson_content_type_can_be_changed_after_creation(): void
    {
        Storage::fake('local');
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'link',
                'content_ref' => 'https://example.test/reading',
            ])
            ->assertOk()
            ->assertJsonPath('data.content_type', 'link')
            ->assertJsonPath('data.content_ref', 'https://example.test/reading')
            ->assertJsonPath('data.source_type', null);
    }

    public function test_a_retype_between_two_upload_types_stores_the_new_file_and_forgets_the_old_measurement(): void
    {
        Storage::fake('local');
        // The compression job is what re-probes duration_seconds; faked here
        // for the same reason ModuleVideoTest fakes it — ffmpeg is not a test
        // dependency (TASK-093 / SETUP.md).
        Queue::fake();
        [$company, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'video',
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->create('lecture.mp4', 500, 'video/mp4'),
            ])
            ->assertOk()
            ->assertJsonPath('data.content_type', 'video');

        $lesson->refresh();

        // The video path, not the pdf one (ADR-028 §2.1 keeps them apart).
        $this->assertStringStartsWith("academy-modules/{$company->id}/", $lesson->content_ref);
        Storage::disk('local')->assertExists($lesson->content_ref);

        // ADR-028 §2.3 — page_count measured the OLD document. Carried over it
        // would become the denominator of content it never described.
        $this->assertNull($lesson->page_count);
        $this->assertNull($lesson->duration_seconds);

        // ...and re-derived, not left null: the new video is queued for the
        // ffprobe pass that gives its watch gate an honest denominator.
        Queue::assertPushed(CompressUploadedVideo::class);
    }

    /**
     * The server-measured columns, on their own, because the assertion above
     * turned out not to cover them: it retypes a PDF (duration_seconds already
     * null) to a video (page_count re-derived as null anyway), so BOTH nulls
     * were true whether or not the code cleared them. Found by mutation —
     * removing the clearing left that test green.
     *
     * This one starts from a video with a measured duration and lands on an
     * external PDF, so nothing else in the write path can null either column.
     */
    public function test_the_server_measured_columns_do_not_survive_a_retype(): void
    {
        Storage::fake('local');
        [$company, $admin, $module] = $this->makeCompanyAdminModule();

        $path = "academy-modules/{$company->id}/".Str::uuid()->toString().'.mp4';
        Storage::disk('local')->put($path, 'fake bytes');

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'video',
            'source_type' => 'upload',
            'content_ref' => $path,
            // ADR-028 §2.3 — ffprobe's answer for the OLD file. It is the
            // denominator of the watch gate; 600 seconds of a video that no
            // longer exists would gate a document that has no seconds at all.
            'duration_seconds' => 600,
            'page_count' => 12,
            // ADR-007 — "only meaningful for an UPLOADED video". Set to a
            // finished state so the assertion below is not satisfied by the
            // column having been null all along (which is how the first
            // version of this test let a mutation through).
            'processing_status' => 'ready',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'pdf',
                'content_ref' => 'https://example.test/handbook.pdf',
            ])
            ->assertOk()
            ->assertJsonPath('data.duration_seconds', null)
            ->assertJsonPath('data.page_count', null);

        $lesson->refresh();
        $this->assertNull($lesson->duration_seconds);
        $this->assertNull($lesson->page_count);
        $this->assertNull($lesson->processing_status);
    }

    // -----------------------------------------------------------------
    // §6.D2 consequence 1 — the stored file
    // -----------------------------------------------------------------

    public function test_the_old_stored_file_is_deleted_when_the_lesson_stops_pointing_at_it(): void
    {
        Storage::fake('local');
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);
        $oldPath = $lesson->content_ref;

        Storage::disk('local')->assertExists($oldPath);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'link',
                'content_ref' => 'https://example.test/reading',
            ])
            ->assertOk();

        // Unreferenced the moment the new content lands — leaving it would be
        // a private-disk file no row points at, forever.
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame('https://example.test/reading', $lesson->refresh()->content_ref);
    }

    public function test_a_retype_to_an_upload_type_cannot_leave_the_lesson_pointing_at_the_old_file(): void
    {
        Storage::fake('local');
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);
        $oldPath = $lesson->content_ref;

        // THE HALF-STATE THIS FEATURE MUST NOT BE ABLE TO PRODUCE: a lesson
        // typed `video` whose content_ref is still the PDF. The create path's
        // `file` rule (requiredIf the type is an upload) is what forbids it,
        // reached from the update path via ValidatesLessonContent.
        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'video',
                'source_type' => 'upload',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $lesson->refresh();
        $this->assertSame('pdf', $lesson->content_type->value);
        $this->assertSame($oldPath, $lesson->content_ref);
        Storage::disk('local')->assertExists($oldPath);
    }

    public function test_a_retype_to_an_external_type_still_demands_a_content_ref(): void
    {
        Storage::fake('local');
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", ['content_type' => 'link'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content_ref');
    }

    public function test_a_retype_reuses_the_create_paths_mime_check(): void
    {
        Storage::fake('local');
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);

        // Not a second copy of the rule — the same one (§6.D1: "reuse that
        // validation, do not write a second copy"). If the update path ever
        // grows its own, this is the test that notices.
        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'image',
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->create('poster.png', 20, 'application/x-msdownload'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_a_retype_to_quiz_clears_the_content_ref(): void
    {
        Storage::fake('local');
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);
        $oldPath = $lesson->content_ref;

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", ['content_type' => 'quiz'])
            ->assertOk()
            ->assertJsonPath('data.content_type', 'quiz')
            ->assertJsonPath('data.content_ref', null);

        $this->assertNull($lesson->refresh()->content_ref);
        Storage::disk('local')->assertMissing($oldPath);
    }

    // -----------------------------------------------------------------
    // §6.D2 consequence 2 — learner progress, and what survives it
    // -----------------------------------------------------------------

    public function test_recorded_learner_progress_is_reset_by_a_content_type_change(): void
    {
        Storage::fake('local');
        [$company, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);

        $reader = User::factory()->agent()->create(['company_id' => $company->id]);
        ModuleLessonProgress::factory()->create([
            'company_id' => $company->id,
            'user_id' => $reader->id,
            'module_lesson_id' => $lesson->id,
            'max_page' => 38,
            'last_page' => 38,
            'total_pages' => 40,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'link',
                'content_ref' => 'https://example.test/reading',
            ])
            ->assertOk();

        // 38 pages of a document that no longer exists. Kept, it would revive
        // as evidence the next time this lesson is typed `pdf` again — against
        // a file it never described, on the BR-1 path.
        $this->assertSame(0, ModuleLessonProgress::withoutGlobalScopes()
            ->where('module_lesson_id', $lesson->id)
            ->count());
    }

    public function test_a_completion_already_earned_survives_a_content_type_change(): void
    {
        Storage::fake('local');
        [$company, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);

        $finished = User::factory()->agent()->create(['company_id' => $company->id]);
        ModuleCompletion::factory()->create([
            'company_id' => $company->id,
            'user_id' => $finished->id,
            'module_lesson_id' => $lesson->id,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'link',
                'content_ref' => 'https://example.test/reading',
            ])
            ->assertOk();

        // ADR-028 §2.3 guard 1 / ADR-029 §3 — grandfathering. Nobody loses a
        // lesson, or the BR-1 certification standing on it, because an admin
        // changed the medium afterwards.
        $this->assertSame(1, ModuleCompletion::withoutGlobalScopes()
            ->where('module_lesson_id', $lesson->id)
            ->where('user_id', $finished->id)
            ->count());
    }

    // -----------------------------------------------------------------
    // §6.D2 consequence 3 — the attached quiz
    // -----------------------------------------------------------------

    public function test_an_attached_quiz_survives_a_content_type_change(): void
    {
        Storage::fake('local');
        [$company, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);

        $quiz = Quiz::factory()->create(['company_id' => $company->id]);
        // Through the Service — ADR-030 §2.1, quiz_id is not fillable and the
        // link may only move here.
        app(QuizService::class)->attach($lesson, $quiz, $admin);

        $lesson->update(['quiz_blocks_completion' => true]);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'video',
                'source_type' => 'embed',
                'content_ref' => 'https://www.youtube.com/embed/abc123',
            ])
            ->assertOk()
            ->assertJsonPath('data.quiz_id', $quiz->id)
            // ADR-029 §2.6 — still blocking. A retype must not quietly switch
            // a completion gate off.
            ->assertJsonPath('data.quiz_blocks_completion', true);

        $this->assertSame($quiz->id, $lesson->refresh()->quiz_id);
    }

    // -----------------------------------------------------------------
    // §6.D2 consequence 4 — is_downloadable
    // -----------------------------------------------------------------

    public function test_is_downloadable_is_reset_rather_than_inherited_by_the_new_content(): void
    {
        Storage::fake('local');
        Queue::fake();
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: true);

        $this->assertTrue($lesson->is_downloadable);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'video',
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->create('lecture.mp4', 500, 'video/mp4'),
            ])
            ->assertOk()
            ->assertJsonPath('data.is_downloadable', false);

        // Left on, LessonCompletionGate::isMeasurable() would keep returning
        // false and the new video's watch gate would be silently disabled —
        // the combination TASK-188 §6.D2 flags as impossible to hold together.
        $this->assertFalse($lesson->refresh()->is_downloadable);
    }

    public function test_is_downloadable_is_prohibited_when_the_new_type_has_no_file_of_ours(): void
    {
        Storage::fake('local');
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: true);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'link',
                'content_ref' => 'https://example.test',
                'is_downloadable' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_downloadable');
    }

    // -----------------------------------------------------------------
    // §6.D3(b) — the audit row
    // -----------------------------------------------------------------

    public function test_a_content_type_change_writes_an_audit_row_with_old_and_new_values(): void
    {
        Storage::fake('local');
        [$company, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: true);

        $reader = User::factory()->agent()->create(['company_id' => $company->id]);
        ModuleLessonProgress::factory()->create([
            'company_id' => $company->id,
            'user_id' => $reader->id,
            'module_lesson_id' => $lesson->id,
            'max_page' => 38,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'link',
                'content_ref' => 'https://example.test/reading',
            ])
            ->assertOk();

        $entry = AuditLog::withoutGlobalScopes()
            ->where('action', 'module_lesson.content_type_changed')
            ->where('auditable_id', $lesson->id)
            ->firstOrFail();

        $this->assertSame($company->id, $entry->company_id);
        $this->assertSame($admin->id, $entry->actor_user_id);
        $this->assertSame(ModuleLesson::class, $entry->auditable_type);
        $this->assertSame('pdf', $entry->old_values['content_type']);
        $this->assertSame('upload', $entry->old_values['source_type']);
        $this->assertTrue($entry->old_values['is_downloadable']);
        $this->assertSame('link', $entry->new_values['content_type']);
        $this->assertFalse($entry->new_values['is_downloadable']);
        // The number the confirmation dialog quoted, frozen next to the name
        // of whoever accepted it.
        $this->assertSame(1, $entry->new_values['progress_rows_reset']);
    }

    public function test_an_update_that_does_not_change_the_content_type_writes_no_audit_row(): void
    {
        Storage::fake('local');
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", ['title' => 'Renamed'])
            ->assertOk();

        $this->assertSame(0, AuditLog::withoutGlobalScopes()
            ->where('action', 'module_lesson.content_type_changed')
            ->count());
    }

    /**
     * REGRESSION GUARD for the validation reuse. The admin edit form posts the
     * lesson back whole, content_type included; if repeating the current type
     * counted as a retype, "rename the lesson" would start demanding a
     * re-upload — a worse trap than the one §1 is fixing.
     */
    public function test_resending_the_same_content_type_is_not_a_retype_and_needs_no_new_file(): void
    {
        Storage::fake('local');
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);
        $oldPath = $lesson->content_ref;

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'pdf',
                'title' => 'Same type, new title',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Same type, new title');

        $lesson->refresh();
        $this->assertSame($oldPath, $lesson->content_ref);
        // page_count is a measurement of a file that has not moved.
        $this->assertSame(40, $lesson->page_count);
        Storage::disk('local')->assertExists($oldPath);
    }

    // -----------------------------------------------------------------
    // §6.D3(a) — the impact preview the confirmation dialog reads
    // -----------------------------------------------------------------

    public function test_the_impact_endpoint_names_the_number_of_affected_learners(): void
    {
        Storage::fake('local');
        [$company, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: true);

        $quiz = Quiz::factory()->create(['company_id' => $company->id]);
        app(QuizService::class)->attach($lesson, $quiz, $admin);

        foreach (range(1, 3) as $ignored) {
            $learner = User::factory()->agent()->create(['company_id' => $company->id]);
            ModuleLessonProgress::factory()->create([
                'company_id' => $company->id,
                'user_id' => $learner->id,
                'module_lesson_id' => $lesson->id,
                'max_page' => 10,
            ]);
        }

        $finished = User::factory()->agent()->create(['company_id' => $company->id]);
        ModuleCompletion::factory()->create([
            'company_id' => $company->id,
            'user_id' => $finished->id,
            'module_lesson_id' => $lesson->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/content-type-change-impact")
            ->assertOk()
            ->assertJsonPath('data.content_type', 'pdf')
            ->assertJsonPath('data.learners_with_progress', 3)
            ->assertJsonPath('data.progress_will_be_reset', true)
            ->assertJsonPath('data.learners_completed', 1)
            ->assertJsonPath('data.completions_are_kept', true)
            ->assertJsonPath('data.stored_file_will_be_deleted', true)
            ->assertJsonPath('data.is_downloadable_will_reset', true)
            ->assertJsonPath('data.quiz_id', $quiz->id)
            ->assertJsonPath('data.quiz_stays_attached', true);
    }

    public function test_the_impact_endpoint_reports_nothing_at_stake_for_an_untouched_lesson(): void
    {
        Storage::fake('local');
        [, $admin, $module] = $this->makeCompanyAdminModule();

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $module->company_id,
            'module_id' => $module->id,
            'content_type' => 'link',
            'source_type' => null,
            'content_ref' => 'https://example.test',
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/content-type-change-impact")
            ->assertOk()
            ->assertJsonPath('data.learners_with_progress', 0)
            ->assertJsonPath('data.progress_will_be_reset', false)
            ->assertJsonPath('data.stored_file_will_be_deleted', false)
            ->assertJsonPath('data.is_downloadable_will_reset', false)
            ->assertJsonPath('data.quiz_stays_attached', false);
    }

    public function test_an_agent_cannot_read_the_impact_of_a_lesson_in_their_own_course(): void
    {
        Storage::fake('local');
        [$company, , , $lesson] = $this->makeStoredPdfLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // Same company, so nothing is hidden by TenantScope — this is
        // ModulePolicy::update doing the work. The counts are how many of
        // their colleagues are behind, which is management data (ADR-028 §4).
        $this->actingAs($agent)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/content-type-change-impact")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // BR-6 / §5 rule 5 — cross-tenant
    // -----------------------------------------------------------------

    public function test_another_companys_admin_cannot_retype_a_lesson_and_gets_a_404_not_a_403(): void
    {
        Storage::fake('local');
        [, , , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);

        $otherCompany = Company::factory()->create();
        $outsider = User::factory()->companyAdmin()->create(['company_id' => $otherCompany->id]);

        // 404, not 403: distinguishing "no such lesson" from "a lesson you may
        // not touch" is the IDOR-adjacent leak §5.5 warns about, and only
        // TenantScope on the route binding produces the former.
        $this->actingAs($outsider)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'link',
                'content_ref' => 'https://attacker.test',
            ])
            ->assertNotFound();

        // Non-tautological: the write is asserted NOT to have happened, and
        // the same payload from the lesson's own admin below is asserted to
        // succeed — so this cannot pass by both sides being empty.
        $lesson->refresh();
        $this->assertSame('pdf', $lesson->content_type->value);
        $this->assertNotSame('https://attacker.test', $lesson->content_ref);
    }

    public function test_the_same_retype_succeeds_for_the_lessons_own_admin(): void
    {
        Storage::fake('local');
        [, $admin, , $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);

        // The positive control for the 404 above: the identical payload, the
        // identical lesson, only the actor's company differs.
        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", [
                'content_type' => 'link',
                'content_ref' => 'https://attacker.test',
            ])
            ->assertOk();

        $this->assertSame('link', $lesson->refresh()->content_type->value);
    }

    public function test_another_companys_admin_cannot_read_the_impact_preview(): void
    {
        Storage::fake('local');
        [$company, , , $lesson] = $this->makeStoredPdfLesson();

        $learner = User::factory()->agent()->create(['company_id' => $company->id]);
        ModuleLessonProgress::factory()->create([
            'company_id' => $company->id,
            'user_id' => $learner->id,
            'module_lesson_id' => $lesson->id,
            'max_page' => 10,
        ]);

        $otherCompany = Company::factory()->create();
        $outsider = User::factory()->companyAdmin()->create(['company_id' => $otherCompany->id]);

        // There IS something to leak here — one learner's progress row exists,
        // and the endpoint would happily count it if the lesson resolved.
        $this->actingAs($outsider)
            ->getJson("/api/v1/module-lessons/{$lesson->id}/content-type-change-impact")
            ->assertNotFound();
    }
}
