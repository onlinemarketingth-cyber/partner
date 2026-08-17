<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductSalesMaterial;
use App\Models\SalesMaterialShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// ADR-007 Decision 3 — signed, time-limited, revocable PUBLIC link for
// one sales material. The public route is unauthenticated (no
// actingAs()) — that's the whole point of these tests.
class SalesMaterialShareLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function uploadMaterial(User $admin, Product $product): ProductSalesMaterial
    {
        $file = UploadedFile::fake()->create('brochure.pdf', 500, 'application/pdf');
        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/sales-materials", ['file' => $file])->assertCreated();

        return ProductSalesMaterial::first();
    }

    public function test_an_agent_can_generate_a_share_link_for_a_material_they_can_view(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $material = $this->uploadMaterial($admin, $product);

        $this->actingAs($agent)
            ->postJson("/api/v1/sales-materials/{$material->id}/share-links", ['expires_in_days' => 7])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['share_url', 'expires_at']]);

        $this->assertSame(1, SalesMaterialShareLink::where('sales_material_id', $material->id)->count());
    }

    public function test_a_usable_share_link_is_publicly_downloadable_without_authentication(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $material = $this->uploadMaterial($admin, $product);

        $this->actingAs($admin)->postJson("/api/v1/sales-materials/{$material->id}/share-links", ['expires_in_days' => 7])->assertCreated();
        $link = SalesMaterialShareLink::first();

        // No actingAs() — this is the whole point: a prospect with no account.
        $this->getJson("/api/v1/share/sales-materials/{$link->token}")->assertOk();

        $this->assertSame(1, $link->fresh()->view_count);
    }

    public function test_an_expired_share_link_is_not_accessible(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $material = $this->uploadMaterial($admin, $product);

        $this->actingAs($admin)->postJson("/api/v1/sales-materials/{$material->id}/share-links", ['expires_in_days' => 1])->assertCreated();
        $link = SalesMaterialShareLink::first();

        Carbon::setTestNow(now()->addDays(2));

        $this->getJson("/api/v1/share/sales-materials/{$link->token}")->assertNotFound();
    }

    public function test_a_revoked_share_link_is_not_accessible_even_before_expiry(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $material = $this->uploadMaterial($admin, $product);

        $this->actingAs($admin)->postJson("/api/v1/sales-materials/{$material->id}/share-links", ['expires_in_days' => 30])->assertCreated();
        $link = SalesMaterialShareLink::first();

        $this->actingAs($admin)->deleteJson("/api/v1/share-links/{$link->id}")->assertNoContent();

        $this->getJson("/api/v1/share/sales-materials/{$link->token}")->assertNotFound();
    }

    public function test_an_unknown_token_returns_404_not_an_error(): void
    {
        $this->getJson('/api/v1/share/sales-materials/not-a-real-token')->assertNotFound();
    }

    public function test_a_cross_company_agent_cannot_generate_a_link_for_a_material_they_cannot_view(): void
    {
        Storage::fake('local');
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => $otherCompany->id]);
        $product = Product::factory()->create(['company_id' => $ownCompany->id]);
        $material = $this->uploadMaterial($admin, $product);

        $this->actingAs($foreignAgent)
            ->postJson("/api/v1/sales-materials/{$material->id}/share-links", ['expires_in_days' => 7])
            ->assertNotFound();
    }
}
