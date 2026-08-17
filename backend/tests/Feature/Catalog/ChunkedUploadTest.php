<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\ChunkedUpload;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
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
}
