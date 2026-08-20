<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\ChunkedUpload;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\VideoProcessingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-094 — chunked upload transport.
 *
 * The cases that matter here are the ones that would be silent holes
 * rather than obvious breakage: another tenant reusing a token, and the
 * cumulative size ceiling. A per-chunk-only size check would pass every
 * individual request while still letting an attacker write unlimited
 * bytes, so that test asserts on the SECOND chunk, not the first.
 */
class ChunkedUploadTest extends TestCase
{
    use RefreshDatabase;

    private function product(Company $company): Product
    {
        return Product::factory()->for($company)->create([
            'brand_id' => Brand::factory()->for($company)->create()->id,
            'category_id' => ProductCategory::factory()->for($company)->create()->id,
        ]);
    }

    public function test_a_file_sent_in_chunks_creates_the_sales_material(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = $this->product($company);

        /*
         * A REAL image, split down the middle — not synthetic filler.
         *
         * Laravel's `mimes:` rule resolves the extension from the file's
         * CONTENT (finfo magic bytes), never from the filename the client
         * sent. So the property this test has to prove is that chunk
         * reassembly is byte-exact enough for sniffing to still work; a
         * first version used "aaaa"/"bbbb" and failed with "must be a file
         * of type: pdf, jpg, ..." — correctly, because those bytes are
         * text/plain. That failure was the test lying, not the transport
         * breaking, and swapping in real bytes is what makes the assertion
         * mean something.
         */
        // $source MUST be held in a variable, not chained. Laravel's fake
        // file wraps a tmpfile() resource that is deleted the moment the
        // object is garbage-collected — inlining this as
        // `file_get_contents(UploadedFile::fake()->image(...)->getRealPath())`
        // destroys the object mid-expression and the read fails with
        // "Failed to open stream: No such file or directory".
        $source = UploadedFile::fake()->image('photo.png', 40, 40);
        $bytes = (string) file_get_contents($source->getRealPath());

        $half = (int) ceil(strlen($bytes) / 2);
        $partOne = substr($bytes, 0, $half);
        $partTwo = substr($bytes, $half);

        $init = $this->actingAs($admin)->postJson('/api/v1/uploads/init', [
            'filename' => 'photo.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
        ])->assertCreated();

        $token = $init->json('data.token');

        $this->actingAs($admin)->post("/api/v1/uploads/{$token}/chunk", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk-0', $partOne),
        ])->assertOk()->assertJsonPath('data.complete', false);

        $this->actingAs($admin)->post("/api/v1/uploads/{$token}/chunk", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk-1', $partTwo),
            'is_last' => '1',
        ])->assertOk()->assertJsonPath('data.complete', true);

        $this->actingAs($admin)
            ->post("/api/v1/products/{$product->id}/sales-materials", ['upload_token' => $token])
            ->assertCreated();

        $this->assertDatabaseHas('product_sales_materials', [
            'product_id' => $product->id,
            'original_filename' => 'photo.png',
            'mime_type' => 'image/png',
        ]);

        // The session row is consumed on use — a token must not be
        // replayable into a second record.
        $this->assertDatabaseMissing('chunked_uploads', ['token' => $token]);
    }

    public function test_another_company_cannot_append_to_or_consume_a_token(): void
    {
        Storage::fake('local');

        $owner = Company::factory()->create();
        $ownerAdmin = User::factory()->companyAdmin()->create(['company_id' => $owner->id]);

        $intruderCompany = Company::factory()->create();
        $intruder = User::factory()->companyAdmin()->create(['company_id' => $intruderCompany->id]);
        $intruderProduct = $this->product($intruderCompany);

        $token = $this->actingAs($ownerAdmin)->postJson('/api/v1/uploads/init', [
            'filename' => 'private.pdf',
            'size_bytes' => 10,
        ])->json('data.token');

        // TenantScope makes it absent rather than forbidden — no leak
        // that the token exists at all (§5 rule 5).
        $this->actingAs($intruder)->post("/api/v1/uploads/{$token}/chunk", [
            'chunk' => UploadedFile::fake()->createWithContent('c', 'xxxxxxxxxx'),
        ])->assertNotFound();

        $this->actingAs($intruder)
            ->post("/api/v1/products/{$intruderProduct->id}/sales-materials", ['upload_token' => $token])
            ->assertNotFound();
    }

    public function test_cumulative_size_is_enforced_across_chunks(): void
    {
        Storage::fake('local');

        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $token = $this->actingAs($admin)->postJson('/api/v1/uploads/init', [
            'filename' => 'huge.mp4',
        ])->json('data.token');

        // Shrink the ceiling rather than sending hundreds of megabytes:
        // the rule under test is "received_bytes + incoming > max_bytes",
        // and the exact numbers are irrelevant to it.
        ChunkedUpload::withoutGlobalScopes()->where('token', $token)->update(['max_bytes' => 15]);

        $this->actingAs($admin)->post("/api/v1/uploads/{$token}/chunk", [
            'chunk' => UploadedFile::fake()->createWithContent('c1', str_repeat('a', 10)),
        ])->assertOk();

        // Individually fine, cumulatively over — this is the case a
        // per-chunk-only check would wave through.
        $this->actingAs($admin)->post("/api/v1/uploads/{$token}/chunk", [
            'chunk' => UploadedFile::fake()->createWithContent('c2', str_repeat('b', 10)),
        ])->assertStatus(422);

        $this->assertDatabaseMissing('chunked_uploads', ['token' => $token]);
    }

    public function test_an_incomplete_token_is_rejected_by_the_create_endpoint(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = $this->product($company);

        $token = $this->actingAs($admin)->postJson('/api/v1/uploads/init', [
            'filename' => 'half.pdf',
            'size_bytes' => 100,
        ])->json('data.token');

        $this->actingAs($admin)->post("/api/v1/uploads/{$token}/chunk", [
            'chunk' => UploadedFile::fake()->createWithContent('c', 'short'),
        ])->assertOk();

        $this->actingAs($admin)
            ->post("/api/v1/products/{$product->id}/sales-materials", ['upload_token' => $token])
            ->assertStatus(422);
    }

    public function test_prune_deletes_stale_sessions_and_their_part_files(): void
    {
        Storage::fake('local');

        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $token = $this->actingAs($admin)->postJson('/api/v1/uploads/init', [
            'filename' => 'abandoned.mp4',
        ])->json('data.token');

        $upload = ChunkedUpload::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        $upload->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('uploads:prune')->assertSuccessful();

        $this->assertDatabaseMissing('chunked_uploads', ['token' => $token]);
        Storage::disk('local')->assertMissing($upload->part_path);
    }

    /**
     * TASK-222 — a SUPER ADMIN has users.company_id = NULL (deliberately;
     * see the users migration). Passing that into forCompany() used to be a
     * fatal TypeError, so `POST /uploads/init` 500'd and a Super Admin could
     * not upload ANY file large enough to be chunked. Found on production
     * with a 198 MB video, 2026-08-20.
     */
    public function test_a_super_admin_with_no_company_can_start_a_chunked_upload(): void
    {
        Storage::fake('local');
        $super = User::factory()->superAdmin()->create(['company_id' => null]);
        $this->assertNull($super->company_id, 'the fixture must reproduce the real Super Admin state');

        $response = $this->actingAs($super)
            ->postJson('/api/v1/uploads/init', [
                'filename' => 'big.mov',
                'mime_type' => 'video/quicktime',
                'size_bytes' => 198 * 1024 * 1024,
            ])
            ->assertCreated();

        $token = $response->json('data.token');
        $this->assertIsString($token);

        // The row exists and is UNBOUND — not silently attached to some
        // company the operator never chose.
        $this->assertDatabaseHas('chunked_uploads', ['token' => $token, 'company_id' => null]);

        // The ceiling falls back to the platform default, which is what an
        // actor belonging to no company should get.
        $response->assertJsonPath('data.max_bytes', config('media.video.max_upload_mb') * 1024 * 1024);
    }

    /**
     * The BR-6 half of the same change: a NULL company_id must not become a
     * row every tenant can reach. TenantScope narrows a Company Admin with
     * `where company_id = :own`, which excludes NULL — so an unbound
     * staging file is invisible to them, token or no token.
     */
    public function test_a_company_admin_cannot_touch_a_super_admins_unbound_upload(): void
    {
        Storage::fake('local');
        $super = User::factory()->superAdmin()->create(['company_id' => null]);

        $token = $this->actingAs($super)
            ->postJson('/api/v1/uploads/init', ['filename' => 'big.mov', 'size_bytes' => 1024])
            ->assertCreated()
            ->json('data.token');

        $admin = User::factory()->companyAdmin()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->actingAs($admin)
            ->post("/api/v1/uploads/{$token}/chunk", [
                'chunk' => UploadedFile::fake()->create('part', 1),
            ])
            ->assertNotFound();
    }

    /**
     * TASK-226 — the company's own ceiling, not the platform default.
     *
     * Reported from production: a human raised Thai Life's video cap to
     * 300 MB in ตั้งค่าวิดีโอ and still got "ไฟล์ใหญ่เกินขนาดที่บริษัทกำหนด
     * (200 MB)". TASK-222 had turned the Super Admin's null company from a
     * 500 into a fallback, and the fallback was the platform default — a
     * better failure, but still the wrong number.
     */
    public function test_a_super_admin_gets_the_named_companys_ceiling_not_the_platform_default(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        VideoProcessingSetting::create([
            'company_id' => $company->id,
            'max_upload_mb' => 300,
            'target_resolution' => '480p',
            'target_bitrate_kbps' => 2500,
        ]);

        $super = User::factory()->superAdmin()->create(['company_id' => null]);

        $this->actingAs($super)
            ->postJson('/api/v1/uploads/init', [
                'filename' => 'big.mov',
                'size_bytes' => 250 * 1024 * 1024,
                'company_id' => $company->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.max_bytes', 300 * 1024 * 1024);

        // ...and the staging row is bound to that company, not left unowned.
        $this->assertDatabaseHas('chunked_uploads', ['company_id' => $company->id]);
    }

    /** "ทุกบริษัท" is a real state of the picker — it must not block an upload. */
    public function test_a_super_admin_who_names_no_company_still_gets_the_platform_default(): void
    {
        Storage::fake('local');
        $super = User::factory()->superAdmin()->create(['company_id' => null]);

        $this->actingAs($super)
            ->postJson('/api/v1/uploads/init', ['filename' => 'big.mov', 'size_bytes' => 1024])
            ->assertCreated()
            ->assertJsonPath('data.max_bytes', config('media.video.max_upload_mb') * 1024 * 1024);
    }

    /**
     * BR-6: a Company Admin does not get to name a company. Borrowing
     * another tenant's (larger) cap would be the whole point of trying.
     */
    public function test_a_company_admins_supplied_company_id_is_ignored(): void
    {
        Storage::fake('local');
        $own = Company::factory()->create();
        $other = Company::factory()->create();
        VideoProcessingSetting::create([
            'company_id' => $other->id,
            'max_upload_mb' => 900,
            'target_resolution' => '480p',
            'target_bitrate_kbps' => 2500,
        ]);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $own->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/uploads/init', [
                'filename' => 'big.mov',
                'size_bytes' => 1024,
                'company_id' => $other->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.max_bytes', config('media.video.max_upload_mb') * 1024 * 1024);

        $this->assertDatabaseHas('chunked_uploads', ['company_id' => $own->id]);
        $this->assertDatabaseMissing('chunked_uploads', ['company_id' => $other->id]);
    }
}
