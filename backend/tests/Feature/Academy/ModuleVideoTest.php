<?php

namespace Tests\Feature\Academy;

use App\Jobs\CompressUploadedVideo;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

// ADR-007/ADR-009 — Academy lesson video: upload OR iframe embed, now
// scoped to a ModuleLesson (content item) under a Module (Section)
// rather than directly on Module.
class ModuleVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_create_an_embedded_video_lesson(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'How to sell', 'content_type' => 'video',
                'source_type' => 'embed', 'content_ref' => 'https://www.youtube.com/embed/abc123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.content_ref', 'https://www.youtube.com/embed/abc123')
            ->assertJsonPath('data.stream_url', null);
    }

    public function test_uploading_a_lesson_video_dispatches_the_compression_job_and_hides_the_raw_path(): void
    {
        Storage::fake('local');
        Queue::fake();
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $file = UploadedFile::fake()->create('training.mp4', 5000, 'video/mp4');

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'Product walkthrough', 'content_type' => 'video',
                'source_type' => 'upload', 'file' => $file,
            ])
            ->assertCreated()
            ->assertJsonPath('data.processing_status', 'pending');

        // The private-disk path is never exposed to the client (Section
        // 5 rule 6) — only a stream_url route.
        $this->assertNull($response->json('data.content_ref'));
        $this->assertNotNull($response->json('data.stream_url'));

        Queue::assertPushed(CompressUploadedVideo::class);
        $this->assertNotNull(ModuleLesson::first()->content_ref); // stored on the model itself, just not serialized
    }

    public function test_a_video_upload_without_source_type_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", ['title' => 'x', 'content_type' => 'video', 'content_ref' => 'https://x.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_type');
    }

    public function test_a_pdf_lesson_is_unaffected_by_the_video_changes(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/modules/{$module->id}/lessons", [
                'title' => 'Handbook', 'content_type' => 'pdf', 'content_ref' => 'https://example.test/doc.pdf',
            ])
            ->assertCreated()
            ->assertJsonPath('data.content_ref', 'https://example.test/doc.pdf')
            ->assertJsonPath('data.source_type', null);
    }

    public function test_streaming_an_uploaded_lesson_video_requires_authentication(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        // Created directly via Eloquent (not the authenticated POST
        // endpoint) so no actingAs() call happens before the
        // unauthenticated assertion below — actingAs() persists across
        // all subsequent requests in the test, so calling it for setup
        // would make the "no auth" request below actually authenticated.
        $contentRef = "academy-modules/{$company->id}/".Str::uuid()->toString().'.mp4';
        Storage::disk('local')->put($contentRef, 'fake video bytes');

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'source_type' => 'upload',
            'content_ref' => $contentRef,
        ]);

        $this->getJson("/api/v1/module-lessons/{$lesson->id}/stream")->assertUnauthorized();
        $this->actingAs($admin)->getJson("/api/v1/module-lessons/{$lesson->id}/stream")->assertOk();
    }
}
