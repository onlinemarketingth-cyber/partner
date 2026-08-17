<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductSalesMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Human-requested — Product Catalog sales/marketing collateral. Files
// are tenant-scoped by path, access-checked before download, never a
// public URL (Section 5 rule 6 pattern, same as ClientDocumentTest).
// Deliberately wider VIEW circle than ClientDocument though: any agent
// in the company can view/download (they need to hand these to
// clients), not just one specific referring agent — upload/delete stay
// Company Admin/Super Admin only (human's explicit choice).
class ProductSalesMaterialTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_upload_a_sales_material(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->create('brochure.pdf', 500, 'application/pdf');

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/sales-materials", ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.original_filename', 'brochure.pdf');

        Storage::disk('local')->assertExists("product-materials/{$company->id}/{$product->id}");
    }

    public function test_agent_cannot_upload_a_sales_material(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->create('brochure.pdf', 500, 'application/pdf');

        $this->actingAs($agent)
            ->postJson("/api/v1/products/{$product->id}/sales-materials", ['file' => $file])
            ->assertForbidden();
    }

    public function test_agent_in_the_same_company_can_view_and_download(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->create('brochure.pdf', 500, 'application/pdf');
        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/sales-materials", ['file' => $file])->assertCreated();
        $materialId = ProductSalesMaterial::first()->id;

        $this->actingAs($agent)->getJson("/api/v1/products/{$product->id}/sales-materials")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($agent)->getJson("/api/v1/sales-materials/{$materialId}/download")->assertOk();
    }

    public function test_cross_company_agent_cannot_view_or_download(): void
    {
        Storage::fake('local');
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $product = Product::factory()->create(['company_id' => $ownCompany->id]);

        $file = UploadedFile::fake()->create('brochure.pdf', 500, 'application/pdf');
        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/sales-materials", ['file' => $file])->assertCreated();
        $materialId = ProductSalesMaterial::first()->id;

        // The parent product itself is invisible cross-tenant
        // (TenantScope) — implicit route-model binding 404s before the
        // Policy even runs.
        $this->actingAs($foreignAgent)->getJson("/api/v1/products/{$product->id}/sales-materials")->assertNotFound();
        $this->actingAs($foreignAgent)->getJson("/api/v1/sales-materials/{$materialId}/download")->assertNotFound();
    }

    public function test_agent_cannot_delete_a_sales_material(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->create('brochure.pdf', 500, 'application/pdf');
        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/sales-materials", ['file' => $file])->assertCreated();
        $materialId = ProductSalesMaterial::first()->id;

        $this->actingAs($agent)->deleteJson("/api/v1/sales-materials/{$materialId}")->assertForbidden();
    }

    public function test_company_admin_can_delete_a_sales_material_and_it_removes_the_file(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->create('brochure.pdf', 500, 'application/pdf');
        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/sales-materials", ['file' => $file])->assertCreated();
        $material = ProductSalesMaterial::first();
        Storage::disk('local')->assertExists($material->file_path);

        $this->actingAs($admin)->deleteJson("/api/v1/sales-materials/{$material->id}")->assertNoContent();

        Storage::disk('local')->assertMissing($material->file_path);
        $this->assertDatabaseMissing('product_sales_materials', ['id' => $material->id]);
    }

    public function test_upload_rejects_a_disallowed_file_type(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $file = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/sales-materials", ['file' => $file])
            ->assertUnprocessable();
    }
}
