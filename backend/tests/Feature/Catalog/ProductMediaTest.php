<?php

namespace Tests\Feature\Catalog;

use App\Jobs\CompressUploadedVideo;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// ADR-007 — Product image/video gallery. Mirrors ProductSalesMaterialTest's
// conventions (reuses ProductPolicy, no dedicated Policy class).
class ProductMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_upload_an_image(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->image('cover.jpg');

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/media", ['media_type' => 'image', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.media_type', 'image');

        $this->assertSame(1, ProductMedia::where('product_id', $product->id)->count());
    }

    public function test_company_admin_can_add_an_embedded_video(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/media", [
                'media_type' => 'video', 'source_type' => 'embed', 'embed_url' => 'https://www.youtube.com/watch?v=abc123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.embed_url', 'https://www.youtube.com/watch?v=abc123')
            ->assertJsonPath('data.stream_url', null);
    }

    public function test_uploading_a_video_dispatches_the_compression_job(): void
    {
        Storage::fake('local');
        Queue::fake();
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->create('demo.mp4', 5000, 'video/mp4');

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/media", ['media_type' => 'video', 'source_type' => 'upload', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.processing_status', 'pending');

        Queue::assertPushed(CompressUploadedVideo::class);
    }

    public function test_setting_a_new_primary_image_unsets_the_previous_one(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $first = UploadedFile::fake()->image('first.jpg');
        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/media", [
            'media_type' => 'image', 'file' => $first, 'is_primary' => true,
        ])->assertCreated();
        $firstMedia = ProductMedia::first();
        $this->assertTrue($firstMedia->is_primary);

        $second = UploadedFile::fake()->image('second.jpg');
        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/media", [
            'media_type' => 'image', 'file' => $second, 'is_primary' => true,
        ])->assertCreated();

        $this->assertFalse($firstMedia->fresh()->is_primary);
        $this->assertSame(1, ProductMedia::where('product_id', $product->id)->where('is_primary', true)->count());
    }

    public function test_agent_cannot_upload_media_but_can_view_the_gallery(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->image('cover.jpg');
        $this->actingAs($agent)
            ->postJson("/api/v1/products/{$product->id}/media", ['media_type' => 'image', 'file' => $file])
            ->assertForbidden();

        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/media", ['media_type' => 'image', 'file' => $file])->assertCreated();

        $this->actingAs($agent)->getJson("/api/v1/products/{$product->id}/media")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cross_company_agent_cannot_view_the_gallery(): void
    {
        Storage::fake('local');
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $product = Product::factory()->create(['company_id' => $ownCompany->id]);

        $this->actingAs($foreignAgent)->getJson("/api/v1/products/{$product->id}/media")->assertNotFound();
    }
}
