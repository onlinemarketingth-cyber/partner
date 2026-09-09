<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use App\Support\Media\ImageThumbnailer;
use App\Support\Media\RangeFileResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 2026-09-09 (human: "🔴 รูปสินค้าไม่มีไฟล์ย่อเลย — ระบบสร้างไฟล์ย่อให้เฉพาะ
 * วิดีโอ ส่วนรูปภาพไม่เคยสร้าง … รูปขนาด 2 MB ถูกโหลดมาทั้งก้อนเพื่อแสดงเป็น
 * สี่เหลี่ยม 52 พิกเซล").
 *
 * ── THE BUG, EXACTLY ──
 *
 * `product_media.thumbnail_path` has existed since ADR-007, and exactly
 * one thing ever wrote it: CompressUploadedVideo, for videos.
 * ProductMediaService::store() set only `file_path` for an image. So
 * ProductResource::thumbnail_url fell through its fallback branch to
 * `route('product-media.stream')` — the ORIGINAL FILE — for every
 * product in the system. The admin catalogue's 52-pixel square and the
 * agent's product card on a phone were both downloading multi-megabyte
 * camera photos.
 *
 * ── AND THE ANSWER TO "DO I HAVE TO RE-UPLOAD?" ──
 *
 * No. The originals are on the disk exactly as they were uploaded, so
 * `media:backfill-thumbnails` makes the small copies from what is
 * already there. That command is tested here too, including that a
 * dry-run writes nothing at all.
 */
class ProductImageThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->companyAdmin()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create(['company_id' => $this->company->id]);
    }

    private function upload(int $width, int $height, ?Product $product = null, ?User $actor = null): ProductMedia
    {
        $response = $this->actingAs($actor ?? $this->admin)
            ->postJson('/api/v1/products/'.($product ?? $this->product)->id.'/media', [
                'media_type' => 'image',
                'purpose' => 'cover',
                'file' => UploadedFile::fake()->image('photo.jpg', $width, $height),
            ])
            ->assertCreated();

        return ProductMedia::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    }

    // ── The upload path ──────────────────────────────────────────────

    public function test_uploading_a_photo_now_produces_a_small_copy(): void
    {
        $media = $this->upload(1600, 1200);

        $this->assertNotNull($media->thumbnail_path, 'รูปที่อัปโหลดใหม่ต้องมีไฟล์ย่อทันที');
        Storage::disk('local')->assertExists($media->thumbnail_path);

        $size = getimagesizefromstring((string) Storage::disk('local')->get($media->thumbnail_path));
        $this->assertNotFalse($size);
        $this->assertLessThanOrEqual(ImageThumbnailer::MAX_EDGE, max((int) $size[0], (int) $size[1]));
    }

    public function test_the_thumbnail_exists_before_the_upload_response_is_answered(): void
    {
        /*
         * Deliberately NOT a queued job (video is, because ffmpeg takes
         * minutes). If this were queued, an admin would upload a photo
         * and the row would show the full-size original until a worker
         * happened to run — and on a host whose worker is not running,
         * forever. That is the bug being fixed.
         */
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/products/{$this->product->id}/media", [
                'media_type' => 'image',
                'file' => UploadedFile::fake()->image('photo.jpg', 1400, 1400),
            ])
            ->assertCreated();

        $this->assertNotNull($response->json('data.thumbnail_url'));
    }

    public function test_a_products_card_image_now_points_at_the_thumbnail_not_the_original(): void
    {
        // The user-visible symptom, at the exact place it was reported:
        // the catalogue list.
        $media = $this->upload(1600, 1200);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.thumbnail_url', route('product-media.thumbnail', $media->id));
    }

    public function test_a_photo_that_is_already_small_still_falls_back_to_the_original(): void
    {
        /*
         * Unchanged behaviour, on purpose. A 200 px image does not get a
         * 480 px "thumbnail"; `thumbnail_path` stays null and the card
         * streams the original — which is what every product did before
         * this change, so nothing regresses for them.
         */
        $media = $this->upload(200, 150);

        $this->assertNull($media->thumbnail_path);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.thumbnail_url', route('product-media.stream', $media->id));
    }

    public function test_a_platform_owned_products_photo_gets_one_too(): void
    {
        // ADR-040 — a shared product has no company, and its media row's
        // company_id is null. The directory it writes into comes from
        // OwnerDirectory, and the thumbnail must land in the same place.
        $shared = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'name' => 'GENESENN Vital Blueprint',
            'price_satang' => 890000,
            'is_active' => true,
            'commission_plan_type' => 'unilevel',
        ]);

        // A platform row belongs to nobody, so only a Super Admin may put
        // a photo on it — ProductPolicy, unchanged by any of this.
        $media = $this->upload(1500, 1000, $shared, User::factory()->superAdmin()->create());

        $this->assertNotNull($media->thumbnail_path);
        $this->assertStringStartsWith("product-media/platform/{$shared->id}/", $media->thumbnail_path);
    }

    public function test_deleting_the_photo_takes_its_small_copy_with_it(): void
    {
        // ProductMediaService::delete() already removed thumbnail_path —
        // but until now no image ever had one, so that line had never
        // actually run for an image.
        $media = $this->upload(1600, 1200);
        $thumbnail = (string) $media->thumbnail_path;

        $this->actingAs($this->admin)->deleteJson("/api/v1/product-media/{$media->id}")->assertNoContent();

        Storage::disk('local')->assertMissing($thumbnail);
        Storage::disk('local')->assertMissing((string) $media->file_path);
    }

    // ── The blur-up ──────────────────────────────────────────────────

    public function test_the_card_carries_a_blur_placeholder_inline(): void
    {
        /*
         * 2026-09-09 (human: "หน้า frontend load รูปมาที่หลังประสบการณ์
         * ไม่ดี ค่อยทำให้ภาพชัดขึ้นเรื่อยๆ").
         *
         * `thumbnail_url` is an authenticated stream: the browser cannot
         * paint it until it has fetched it, which is the gap the human is
         * describing. This one field travels WITH the list, so the blurred
         * shape of every product is on screen in the first paint.
         */
        $this->upload(1600, 1200);

        $placeholder = $this->actingAs($this->admin)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->json('data.0.thumbnail_placeholder');

        $this->assertIsString($placeholder);
        $this->assertStringStartsWith('data:image/', $placeholder);
    }

    public function test_the_blur_and_the_photo_are_always_the_same_picture(): void
    {
        /*
         * Both fields resolve through ProductResource::cardMedia(), so they
         * cannot pick different rows. A blur that sharpens into a DIFFERENT
         * photo is more unsettling than no blur at all — and with two
         * copies of the "cover first, then primary, then first" rule it was
         * one edit away from happening.
         */
        $this->upload(1600, 1200);
        $second = $this->upload(1500, 1000);

        // Make the SECOND upload the primary cover.
        $this->actingAs($this->admin)
            ->putJson("/api/v1/product-media/{$second->id}", ['is_primary' => true])
            ->assertOk();

        $row = $this->actingAs($this->admin)->getJson('/api/v1/products')->assertOk()->json('data.0');

        $this->assertSame(route('product-media.thumbnail', $second->id), $row['thumbnail_url']);
        $this->assertSame($second->refresh()->placeholder, $row['thumbnail_placeholder']);
    }

    public function test_a_gallery_row_carries_its_own_placeholder(): void
    {
        // The product page's tile strip reads this one, not the card's.
        $media = $this->upload(1600, 1200);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/products/{$this->product->id}/media")
            ->assertOk()
            ->assertJsonPath('data.0.placeholder', $media->refresh()->placeholder);

        $this->assertNotNull($media->placeholder);
    }

    public function test_even_a_photo_too_small_to_need_a_thumbnail_gets_a_blur(): void
    {
        /*
         * The two are independent. A 200px image legitimately never gets a
         * thumbnail — but it is still fetched through an authenticated
         * stream, so it still has the grey-box gap the blur is for.
         */
        $media = $this->upload(200, 150);

        $this->assertNull($media->thumbnail_path);
        $this->assertNotNull($media->placeholder);
    }

    public function test_the_backfill_adds_a_blur_to_rows_that_only_have_a_thumbnail(): void
    {
        // The shape of every row after the previous release: a thumbnail
        // was generated, the placeholder column did not exist yet.
        $media = $this->upload(1600, 1200);
        $media->forceFill(['placeholder' => null])->save();
        $thumbnail = $media->thumbnail_path;

        $this->artisan('media:backfill-thumbnails')->assertSuccessful();

        $media->refresh();
        $this->assertNotNull($media->placeholder);
        $this->assertSame($thumbnail, $media->thumbnail_path, 'ไฟล์ย่อเดิมต้องไม่ถูกสร้างใหม่');
    }

    // ── Letting the browser keep it ──────────────────────────────────

    public function test_a_photo_may_be_kept_by_the_browser_that_was_allowed_to_see_it(): void
    {
        /*
         * Small files still cost a round trip each, and an agent's grid
         * asks for the same dozen photos on every visit. `private` is
         * what keeps this inside §5 rule 6: no shared cache may hold it,
         * only the browser that already passed the Policy.
         */
        $media = $this->upload(1600, 1200);

        $this->assertCacheControl(
            $this->actingAs($this->admin)->get("/api/v1/product-media/{$media->id}/thumbnail")->assertOk(),
            RangeFileResponder::CACHE_PRODUCT_IMAGE,
        );

        $this->assertCacheControl(
            $this->actingAs($this->admin)->get("/api/v1/product-media/{$media->id}/stream")->assertOk(),
            RangeFileResponder::CACHE_PRODUCT_IMAGE,
        );
    }

    /**
     * Compared as a SET of directives, not as a string: Symfony
     * re-orders them alphabetically on the way out, so an exact string
     * match would be asserting Symfony's sort order rather than our
     * caching policy.
     */
    private function assertCacheControl(TestResponse $response, string $expected): void
    {
        $actual = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        $wanted = array_map('trim', explode(',', $expected));

        sort($actual);
        sort($wanted);

        $this->assertSame($wanted, $actual);
    }

    public function test_a_video_is_still_stored_nowhere(): void
    {
        // The exception is for PHOTOGRAPHS only. Course material and
        // uploaded video keep the original no-store rule — one shared
        // responder, two deliberate answers.
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/products/{$this->product->id}/media", [
                'media_type' => 'video',
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->create('demo.mp4', 200, 'video/mp4'),
            ])
            ->assertCreated();

        $this->assertCacheControl(
            $this->actingAs($this->admin)->get('/api/v1/product-media/'.$response->json('data.id').'/stream')->assertOk(),
            RangeFileResponder::CACHE_NONE,
        );
    }

    public function test_someone_elses_photo_is_still_refused(): void
    {
        // Caching changes which bytes a browser may keep. It must not
        // change who is allowed to ask for them (BR-6).
        $media = $this->upload(1600, 1200);
        $outsider = User::factory()->companyAdmin()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($outsider)
            ->get("/api/v1/product-media/{$media->id}/thumbnail")
            ->assertNotFound();
    }

    // ── The backfill: no re-uploading ────────────────────────────────

    public function test_the_backfill_makes_small_copies_of_photos_uploaded_before_this_existed(): void
    {
        $media = $this->upload(1600, 1200);

        // Put the row back the way every existing production row looks:
        // an original on disk, and no thumbnail.
        Storage::disk('local')->delete((string) $media->thumbnail_path);
        $media->forceFill(['thumbnail_path' => null])->save();

        $this->artisan('media:backfill-thumbnails')->assertSuccessful();

        $media->refresh();
        $this->assertNotNull($media->thumbnail_path);
        Storage::disk('local')->assertExists($media->thumbnail_path);
    }

    public function test_a_dry_run_writes_absolutely_nothing(): void
    {
        /*
         * An operator's first move on production is --dry-run, and it has
         * to be true: no row touched, and no stray file left behind by
         * the measuring pass either.
         */
        $media = $this->upload(1600, 1200);
        Storage::disk('local')->delete((string) $media->thumbnail_path);
        $media->forceFill(['thumbnail_path' => null])->save();

        $before = Storage::disk('local')->allFiles();

        $this->artisan('media:backfill-thumbnails --dry-run')->assertSuccessful();

        $this->assertNull($media->refresh()->thumbnail_path);
        $this->assertSame($before, Storage::disk('local')->allFiles());
    }

    public function test_running_it_twice_does_not_make_a_second_copy(): void
    {
        $media = $this->upload(1600, 1200);
        Storage::disk('local')->delete((string) $media->thumbnail_path);
        $media->forceFill(['thumbnail_path' => null])->save();

        $this->artisan('media:backfill-thumbnails')->assertSuccessful();
        $first = $media->refresh()->thumbnail_path;

        $this->artisan('media:backfill-thumbnails')->assertSuccessful();

        $this->assertSame($first, $media->refresh()->thumbnail_path);
    }

    public function test_a_row_whose_original_is_gone_is_reported_not_fatal(): void
    {
        // Files disappear — a restored backup, a half-finished manual
        // move. One unreadable row must not stop the other nine hundred.
        $missing = $this->upload(1600, 1200);
        $healthy = $this->upload(1500, 1100);

        foreach ([$missing, $healthy] as $media) {
            Storage::disk('local')->delete((string) $media->thumbnail_path);
            $media->forceFill(['thumbnail_path' => null])->save();
        }

        Storage::disk('local')->delete((string) $missing->file_path);

        $this->artisan('media:backfill-thumbnails')->assertSuccessful();

        $this->assertNull($missing->refresh()->thumbnail_path);
        $this->assertNotNull($healthy->refresh()->thumbnail_path);
    }
}
