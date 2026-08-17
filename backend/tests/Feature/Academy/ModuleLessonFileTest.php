<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-142 / ADR-028 §2.1, §2.2 — an Academy lesson can hold an uploaded
 * PDF or image, stored privately and served only after an authorization
 * check (CLAUDE.md §5 rule 6, §6, BR-6).
 */
class ModuleLessonFileTest extends TestCase
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

    // -----------------------------------------------------------------
    // Upload + storage path (ADR-028 §2.1)
    // -----------------------------------------------------------------

    public function test_a_pdf_lesson_can_be_uploaded_and_is_stored_under_the_lesson_scoped_path(): void
    {
        Storage::fake('local');
        [$company, $admin, $module] = $this->makeCompanyAdminModule();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'Handbook',
                'content_type' => 'pdf',
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->create('handbook.pdf', 120, 'application/pdf'),
            ])
            ->assertCreated();

        // §5 rule 6 — the private-disk path is never serialized.
        $this->assertNull($response->json('data.content_ref'));
        $this->assertNotNull($response->json('data.stream_url'));
        $this->assertFalse($response->json('data.is_downloadable'));

        $lesson = ModuleLesson::first();

        // ADR-028 §2.1 — note the lesson_id segment the legacy video path
        // (academy-modules/{company_id}/) does not have.
        $this->assertStringStartsWith("academy-lessons/{$company->id}/{$lesson->id}/", $lesson->content_ref);
        Storage::disk('local')->assertExists($lesson->content_ref);
    }

    public function test_an_image_lesson_can_be_uploaded(): void
    {
        Storage::fake('local');
        [, $admin, $module] = $this->makeCompanyAdminModule();

        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'Poster',
                'content_type' => 'image',
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->image('poster.png'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.content_type', 'image');
    }

    public function test_an_external_pdf_url_lesson_still_works_unchanged(): void
    {
        [, $admin, $module] = $this->makeCompanyAdminModule();

        // Regression guard for ADR-028 §2.1's "upload is ADDED, not
        // substituted": external URLs remain supported for pdf/link.
        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'External handbook',
                'content_type' => 'pdf',
                'content_ref' => 'https://example.test/doc.pdf',
            ])
            ->assertCreated()
            ->assertJsonPath('data.content_ref', 'https://example.test/doc.pdf')
            ->assertJsonPath('data.source_type', null)
            ->assertJsonPath('data.stream_url', null);
    }

    public function test_a_client_supplied_content_ref_is_rejected_for_an_upload(): void
    {
        Storage::fake('local');
        [, $admin, $module] = $this->makeCompanyAdminModule();

        // §5 rule 6 — the server owns the path. Accepting one here would
        // let a caller repoint a lesson at any file on the private disk.
        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'Sneaky',
                'content_type' => 'pdf',
                'source_type' => 'upload',
                'content_ref' => 'academy-lessons/999/1/secret.pdf',
                'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content_ref');
    }

    // -----------------------------------------------------------------
    // Mime enforcement (TASK-142 AC: rejected by MIME, not extension)
    // -----------------------------------------------------------------

    public function test_an_executable_renamed_to_pdf_is_rejected_by_mime_not_extension(): void
    {
        Storage::fake('local');
        [, $admin, $module] = $this->makeCompanyAdminModule();

        // The NAME ends in .pdf; the sniffed type does not. `mimes:`
        // validates the latter, which is the whole point.
        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'Definitely a document',
                'content_type' => 'pdf',
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->create('payload.pdf', 20, 'application/x-msdownload'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ModuleLesson::count());
    }

    public function test_a_pdf_upload_is_rejected_on_an_image_lesson(): void
    {
        Storage::fake('local');
        [, $admin, $module] = $this->makeCompanyAdminModule();

        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'Wrong type',
                'content_type' => 'image',
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_a_file_over_the_platform_ceiling_is_rejected(): void
    {
        Storage::fake('local');
        [, $admin, $module] = $this->makeCompanyAdminModule();

        // ADR-028 §4 — 20 MB platform-wide, read from
        // config('media.pdf.max_upload_mb'). Read from config here too, so
        // this test tracks the setting instead of freezing a literal.
        $overCeilingKb = ((int) config('media.pdf.max_upload_mb') * 1024) + 1;

        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'Too big',
                'content_type' => 'pdf',
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->create('huge.pdf', $overCeilingKb, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    // -----------------------------------------------------------------
    // Streaming + disposition (ADR-028 §2.2)
    // -----------------------------------------------------------------

    /** @return array{0: Company, 1: ModuleLesson} */
    private function makeStoredPdfLesson(bool $isDownloadable = false): array
    {
        $company = Company::factory()->create();
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $path = "academy-lessons/{$company->id}/1/".Str::uuid()->toString().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 fake bytes');

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'pdf',
            'source_type' => 'upload',
            'content_ref' => $path,
            'is_downloadable' => $isDownloadable,
        ]);

        return [$company, $lesson];
    }

    public function test_an_uploaded_pdf_streams_back_byte_identical_and_inline(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredPdfLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $response = $this->actingAs($agent)->get("/api/v1/module-lessons/{$lesson->id}/stream");

        $response->assertOk();
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 fake bytes', $response->streamedContent());
    }

    public function test_a_downloadable_lesson_streams_as_an_attachment_but_can_still_be_asked_for_inline(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredPdfLesson(isDownloadable: true);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // ADR-028 §2.2 — attachment once downloadable...
        $this->actingAs($agent)
            ->get("/api/v1/module-lessons/{$lesson->id}/stream")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="'.basename($lesson->content_ref).'"');

        // ...and inline on request, so the in-app reader still renders it.
        // This grants nothing: the learner may already keep the file.
        $inline = $this->actingAs($agent)->get("/api/v1/module-lessons/{$lesson->id}/stream?inline=1");
        $this->assertStringStartsWith('inline', $inline->headers->get('Content-Disposition'));
    }

    public function test_a_non_downloadable_lesson_can_never_be_forced_to_attachment(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredPdfLesson(isDownloadable: false);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // There is deliberately no query parameter that asks for one.
        $response = $this->actingAs($agent)->get("/api/v1/module-lessons/{$lesson->id}/stream?inline=0");

        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
    }

    // -----------------------------------------------------------------
    // §5 rule 5/6 — no public URL, no cross-tenant access
    // -----------------------------------------------------------------

    public function test_streaming_a_lesson_file_requires_authentication(): void
    {
        Storage::fake('local');
        // Built via Eloquent, never via an authenticated POST: actingAs()
        // persists for the rest of the test, so setting up through the API
        // would make the "unauthenticated" assertion below a false pass
        // (see ModuleVideoTest for the same trap).
        [, $lesson] = $this->makeStoredPdfLesson();

        $this->getJson("/api/v1/module-lessons/{$lesson->id}/stream")->assertUnauthorized();
    }

    public function test_another_companys_agent_cannot_stream_a_lesson_file(): void
    {
        Storage::fake('local');
        [, $lesson] = $this->makeStoredPdfLesson();

        $otherCompany = Company::factory()->create();
        $outsider = User::factory()->agent()->create(['company_id' => $otherCompany->id]);

        // BR-6 / §5 rule 5 — guessing the id must not reach the bytes.
        $this->actingAs($outsider)
            ->get("/api/v1/module-lessons/{$lesson->id}/stream")
            ->assertNotFound();
    }

    public function test_an_embedded_video_lesson_has_nothing_to_stream(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'video',
            'source_type' => 'embed',
            'content_ref' => 'https://www.youtube.com/embed/abc123',
        ]);

        $this->actingAs($admin)
            ->get("/api/v1/module-lessons/{$lesson->id}/stream")
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // is_downloadable (ADR-028 §2.2)
    // -----------------------------------------------------------------

    public function test_is_downloadable_can_be_flipped_on_an_uploaded_lesson(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredPdfLesson();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/module-lessons/{$lesson->id}", ['is_downloadable' => true])
            ->assertOk()
            ->assertJsonPath('data.is_downloadable', true);
    }

    public function test_is_downloadable_is_prohibited_on_a_lesson_with_no_file_of_ours(): void
    {
        [, $admin, $module] = $this->makeCompanyAdminModule();

        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'External',
                'content_type' => 'link',
                'content_ref' => 'https://example.test',
                'is_downloadable' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_downloadable');
    }
}
