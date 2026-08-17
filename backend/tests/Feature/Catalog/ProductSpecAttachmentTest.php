<?php

namespace Tests\Feature\Catalog;

use App\Jobs\GeneratePdfThumbnail;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductSpecAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

// ADR-008 — Product's spec image/PDF gallery. Mirrors ProductMediaTest's
// conventions (reuses ProductPolicy, no dedicated Policy class).
class ProductSpecAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_upload_an_image(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->image('spec-cover.jpg');

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/spec-attachments", ['media_type' => 'image', 'source_type' => 'upload', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.media_type', 'image');

        $this->assertSame(1, ProductSpecAttachment::where('product_id', $product->id)->count());
    }

    public function test_company_admin_can_upload_a_pdf_and_thumbnail_job_is_dispatched(): void
    {
        Storage::fake('local');
        Queue::fake();
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->create('spec-sheet.pdf', 500, 'application/pdf');

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/spec-attachments", ['media_type' => 'pdf', 'source_type' => 'upload', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.media_type', 'pdf')
            ->assertJsonPath('data.processing_status', 'pending');

        Queue::assertPushed(GeneratePdfThumbnail::class);
    }

    public function test_company_admin_can_add_an_embedded_pdf(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/spec-attachments", [
                'media_type' => 'pdf', 'source_type' => 'embed', 'embed_url' => 'https://example.test/spec-sheet.pdf',
            ])
            ->assertCreated()
            ->assertJsonPath('data.embed_url', 'https://example.test/spec-sheet.pdf')
            ->assertJsonPath('data.stream_url', null);
    }

    public function test_company_admin_can_update_sort_order(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->image('spec-cover.jpg');
        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/spec-attachments", ['media_type' => 'image', 'source_type' => 'upload', 'file' => $file])
            ->assertCreated();
        $attachment = ProductSpecAttachment::first();

        $this->actingAs($admin)
            ->putJson("/api/v1/product-spec-attachments/{$attachment->id}", ['sort_order' => 5])
            ->assertOk()
            ->assertJsonPath('data.sort_order', 5);

        $this->assertSame(5, $attachment->fresh()->sort_order);
    }

    public function test_deleting_an_attachment_removes_its_files_from_disk(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->image('spec-cover.jpg');
        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/spec-attachments", ['media_type' => 'image', 'source_type' => 'upload', 'file' => $file])
            ->assertCreated();
        $attachment = ProductSpecAttachment::first();
        $filePath = $attachment->file_path;
        Storage::disk('local')->assertExists($filePath);

        $this->actingAs($admin)->deleteJson("/api/v1/product-spec-attachments/{$attachment->id}")->assertNoContent();

        Storage::disk('local')->assertMissing($filePath);
        $this->assertDatabaseMissing('product_spec_attachments', ['id' => $attachment->id]);
    }

    public function test_agent_cannot_upload_but_can_view_the_gallery(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->image('spec-cover.jpg');
        $this->actingAs($agent)
            ->postJson("/api/v1/products/{$product->id}/spec-attachments", ['media_type' => 'image', 'source_type' => 'upload', 'file' => $file])
            ->assertForbidden();

        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/spec-attachments", ['media_type' => 'image', 'source_type' => 'upload', 'file' => $file])->assertCreated();

        $this->actingAs($agent)->getJson("/api/v1/products/{$product->id}/spec-attachments")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_streaming_an_uploaded_attachment_requires_authentication(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        // Created directly via Eloquent (not the authenticated POST
        // endpoint) so no actingAs() call happens before the
        // unauthenticated assertion below — actingAs() persists across
        // all subsequent requests in the test, so calling it for setup
        // would make the "no auth" request below actually authenticated.
        $filePath = "product-spec-attachments/{$company->id}/{$product->id}/".Str::uuid()->toString().'.jpg';
        Storage::disk('local')->put($filePath, 'fake image bytes');

        $attachment = ProductSpecAttachment::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'uploaded_by_user_id' => $admin->id,
            'media_type' => 'image',
            'source_type' => 'upload',
            'file_path' => $filePath,
        ]);

        $this->getJson("/api/v1/product-spec-attachments/{$attachment->id}/stream")->assertUnauthorized();
        $this->actingAs($admin)->getJson("/api/v1/product-spec-attachments/{$attachment->id}/stream")->assertOk();
    }

    public function test_thumbnail_requires_authentication_and_404s_when_absent(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $filePath = "product-spec-attachments/{$company->id}/{$product->id}/".Str::uuid()->toString().'.pdf';
        Storage::disk('local')->put($filePath, 'fake pdf bytes');

        $attachment = ProductSpecAttachment::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'uploaded_by_user_id' => $admin->id,
            'media_type' => 'pdf',
            'source_type' => 'upload',
            'file_path' => $filePath,
        ]);

        $this->getJson("/api/v1/product-spec-attachments/{$attachment->id}/thumbnail")->assertUnauthorized();
        $this->actingAs($admin)->getJson("/api/v1/product-spec-attachments/{$attachment->id}/thumbnail")->assertNotFound();
    }

    public function test_cross_company_agent_cannot_view_the_gallery(): void
    {
        Storage::fake('local');
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $product = Product::factory()->create(['company_id' => $ownCompany->id]);

        $this->actingAs($foreignAgent)->getJson("/api/v1/products/{$product->id}/spec-attachments")->assertNotFound();
    }

    public function test_cross_company_admin_cannot_manage_another_companys_attachments(): void
    {
        Storage::fake('local');
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $otherAdmin = User::factory()->companyAdmin()->create(['company_id' => $otherCompany->id]);
        $product = Product::factory()->create(['company_id' => $ownCompany->id]);

        $filePath = "product-spec-attachments/{$ownCompany->id}/{$product->id}/".Str::uuid()->toString().'.jpg';
        Storage::disk('local')->put($filePath, 'fake image bytes');
        $attachment = ProductSpecAttachment::create([
            'company_id' => $ownCompany->id,
            'product_id' => $product->id,
            'uploaded_by_user_id' => User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id])->id,
            'media_type' => 'image',
            'source_type' => 'upload',
            'file_path' => $filePath,
        ]);

        $this->actingAs($otherAdmin)->putJson("/api/v1/product-spec-attachments/{$attachment->id}", ['sort_order' => 1])->assertNotFound();
        $this->actingAs($otherAdmin)->deleteJson("/api/v1/product-spec-attachments/{$attachment->id}")->assertNotFound();
        $this->actingAs($otherAdmin)->getJson("/api/v1/products/{$product->id}/spec-attachments")->assertNotFound();
    }
}
