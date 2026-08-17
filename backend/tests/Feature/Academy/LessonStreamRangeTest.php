<?php

namespace Tests\Feature\Academy;

use App\Models\CertTier;
use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleLesson;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-143 / ADR-028 §2.5 — HTTP byte-range support on the private lesson
 * stream, so seek and resume are usable without downloading the whole file
 * first.
 *
 * The security half of this task matters as much as the protocol half:
 * TASK-143 R4 calls "make the file publicly reachable so the browser can
 * range-request it" an automatic rejection (§5 rule 6). The last two tests
 * here assert that the auth check still runs BEFORE any bytes, including
 * on a range request.
 */
class LessonStreamRangeTest extends TestCase
{
    use RefreshDatabase;

    // 26 bytes, so byte offsets are readable in the assertions below.
    private const BODY = 'abcdefghijklmnopqrstuvwxyz';

    /** @return array{0: Company, 1: ModuleLesson} */
    private function makeStoredVideoLesson(): array
    {
        $company = Company::factory()->create();
        $tier = CertTier::factory()->create();
        $module = Module::factory()->for($company)->create(['cert_tier_id' => $tier->id]);

        $path = "academy-modules/{$company->id}/".Str::uuid()->toString().'.mp4';
        Storage::disk('local')->put($path, self::BODY);

        $lesson = ModuleLesson::factory()->create([
            'company_id' => $company->id,
            'module_id' => $module->id,
            'content_type' => 'video',
            'source_type' => 'upload',
            'content_ref' => $path,
        ]);

        return [$company, $lesson];
    }

    public function test_a_request_without_a_range_header_returns_the_whole_file_and_advertises_range_support(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $response = $this->actingAs($agent)->get("/api/v1/module-lessons/{$lesson->id}/stream");

        $response->assertOk()
            // Without this header a browser will not even attempt to seek.
            ->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame(self::BODY, $response->streamedContent());
    }

    public function test_a_range_request_returns_206_with_the_correct_content_range_and_only_those_bytes(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $response = $this->actingAs($agent)
            ->withHeaders(['Range' => 'bytes=5-9'])
            ->get("/api/v1/module-lessons/{$lesson->id}/stream");

        $response->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 5-9/26')
            ->assertHeader('Content-Length', '5');

        $this->assertSame('fghij', $response->streamedContent());
    }

    public function test_an_open_ended_range_runs_to_the_end_of_the_file(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $response = $this->actingAs($agent)
            ->withHeaders(['Range' => 'bytes=20-'])
            ->get("/api/v1/module-lessons/{$lesson->id}/stream");

        $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 20-25/26');
        $this->assertSame('uvwxyz', $response->streamedContent());
    }

    public function test_a_suffix_range_returns_the_last_n_bytes(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $response = $this->actingAs($agent)
            ->withHeaders(['Range' => 'bytes=-4'])
            ->get("/api/v1/module-lessons/{$lesson->id}/stream");

        $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 22-25/26');
        $this->assertSame('wxyz', $response->streamedContent());
    }

    public function test_a_last_byte_past_the_end_is_clamped_rather_than_rejected(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // RFC 9110 §14.1.2 — a last-byte-pos beyond EOF is clamped; only a
        // FIRST-byte-pos beyond EOF is unsatisfiable.
        $response = $this->actingAs($agent)
            ->withHeaders(['Range' => 'bytes=24-9999'])
            ->get("/api/v1/module-lessons/{$lesson->id}/stream");

        $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 24-25/26');
        $this->assertSame('yz', $response->streamedContent());
    }

    public function test_an_unsatisfiable_range_returns_416_and_not_the_whole_file(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // Answering 200 with the whole body here (the naive fallback) would
        // hand a seeking player everything it did not ask for.
        $this->actingAs($agent)
            ->withHeaders(['Range' => 'bytes=9999-'])
            ->get("/api/v1/module-lessons/{$lesson->id}/stream")
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */26');
    }

    public function test_a_malformed_range_header_falls_back_to_the_whole_file(): void
    {
        Storage::fake('local');
        [$company, $lesson] = $this->makeStoredVideoLesson();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        // RFC 9110 §14.2 permits ignoring a Range we do not wish to
        // satisfy. Deliberately NOT a 416: the request is not asking for
        // an impossible byte range, it is asking in a form we don't serve.
        $response = $this->actingAs($agent)
            ->withHeaders(['Range' => 'items=0-1'])
            ->get("/api/v1/module-lessons/{$lesson->id}/stream");

        $response->assertOk();
        $this->assertSame(self::BODY, $response->streamedContent());
    }

    // -----------------------------------------------------------------
    // §5 rule 6 — range support must not have widened access
    // -----------------------------------------------------------------

    public function test_a_range_request_from_another_company_is_refused_before_any_bytes(): void
    {
        Storage::fake('local');
        [, $lesson] = $this->makeStoredVideoLesson();

        $outsider = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $response = $this->actingAs($outsider)
            ->withHeaders(['Range' => 'bytes=0-4'])
            ->get("/api/v1/module-lessons/{$lesson->id}/stream");

        $response->assertNotFound();
        $this->assertStringNotContainsString('abcde', $response->getContent());
    }

    public function test_an_unauthenticated_range_request_is_refused_before_any_bytes(): void
    {
        Storage::fake('local');
        [, $lesson] = $this->makeStoredVideoLesson();

        $this->withHeaders(['Range' => 'bytes=0-4', 'Accept' => 'application/json'])
            ->get("/api/v1/module-lessons/{$lesson->id}/stream")
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // The other private streams TASK-143 asked us to check
    // -----------------------------------------------------------------

    public function test_product_media_stream_also_supports_range(): void
    {
        // TASK-143 asked us to check ProductMedia and sales materials too,
        // since both can carry an uploaded video (ADR-007). ProductMedia
        // has no factory, so this drives the real upload endpoint — which
        // also proves the change did not break the existing write path.
        Storage::fake('local');

        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $mediaId = $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/media", [
                'media_type' => 'image',
                'file' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg'),
            ])
            ->assertCreated()
            ->json('data.id');

        $media = ProductMedia::findOrFail($mediaId);
        $size = Storage::disk('local')->size($media->file_path);

        $this->actingAs($admin)
            ->withHeaders(['Range' => 'bytes=0-3'])
            ->get("/api/v1/product-media/{$mediaId}/stream")
            ->assertStatus(206)
            ->assertHeader('Content-Range', "bytes 0-3/{$size}")
            ->assertHeader('Content-Length', '4');
    }
}
