<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\Product;
use App\Models\StorefrontBanner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// TASK-068 / ADR-020 row 2.
class StorefrontBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_create_a_banner_pointing_at_their_own_product(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        $response = $this->actingAs($admin)->post('/api/v1/storefront-banners', [
            'product_id' => $product->id,
            'title' => 'ลดราคาพิเศษ',
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertCreated();

        $banner = StorefrontBanner::find($response->json('data.id'));
        $this->assertSame($company->id, $banner->company_id);
        $this->assertNotNull($banner->image_path);
        Storage::disk('public')->assertExists($banner->image_path);
        $this->assertSame($product->id, $response->json('data.product.id'));
    }

    public function test_creating_a_banner_without_an_image_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        $this->actingAs($admin)->postJson('/api/v1/storefront-banners', [
            'product_id' => $product->id,
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_banner_product_id_must_belong_to_the_same_company(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $foreignProduct = Product::factory()->for($otherCompany)->create();

        // ADR-020 decision #2 — a banner's product must be in the SAME
        // company; never trust the client (BR-6).
        $this->actingAs($admin)->postJson('/api/v1/storefront-banners', [
            'product_id' => $foreignProduct->id,
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_agent_can_read_banners_but_cannot_create_one(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();
        StorefrontBanner::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'image_path' => 'storefront-banners/existing.jpg',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($agent)->getJson('/api/v1/storefront-banners')->assertOk();

        $this->actingAs($agent)->postJson('/api/v1/storefront-banners', [
            'product_id' => $product->id,
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertStatus(403);
    }

    public function test_cross_company_banner_access_is_rejected(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $otherProduct = Product::factory()->for($otherCompany)->create();
        $foreignBanner = StorefrontBanner::create([
            'company_id' => $otherCompany->id,
            'product_id' => $otherProduct->id,
            'image_path' => 'storefront-banners/foreign.jpg',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        // TenantScope filters the {storefront_banner} route-model-binding
        // query itself, same convention as ProductTest's cross-tenant case.
        $this->actingAs($admin)->getJson("/api/v1/storefront-banners/{$foreignBanner->id}")->assertNotFound();
        $this->actingAs($admin)->deleteJson("/api/v1/storefront-banners/{$foreignBanner->id}")->assertNotFound();
    }

    // TASK-072 — human-confirmed via AskUserQuestion (2026-08-02): 3 fixed
    // placement spots on ProductBrowseView.vue.
    public function test_banner_defaults_to_top_placement_when_omitted(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        $response = $this->actingAs($admin)->post('/api/v1/storefront-banners', [
            'product_id' => $product->id,
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertCreated();

        $this->assertSame('top', $response->json('data.placement'));
    }

    public function test_banner_can_be_created_with_an_explicit_placement(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        $response = $this->actingAs($admin)->post('/api/v1/storefront-banners', [
            'product_id' => $product->id,
            'placement' => 'middle',
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertCreated();

        $this->assertSame('middle', $response->json('data.placement'));
    }

    public function test_banner_rejects_an_invalid_placement_value(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        $this->actingAs($admin)->postJson('/api/v1/storefront-banners', [
            'product_id' => $product->id,
            'placement' => 'sidebar',
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('placement');
    }

    public function test_banner_placement_can_be_updated(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();
        $banner = StorefrontBanner::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'image_path' => 'storefront-banners/existing.jpg',
            'placement' => 'top',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson("/api/v1/storefront-banners/{$banner->id}", [
            'placement' => 'bottom',
        ])->assertOk()->assertJsonPath('data.placement', 'bottom');
    }

    public function test_banner_list_can_be_filtered_by_placement(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();
        StorefrontBanner::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'image_path' => 'storefront-banners/top.jpg',
            'placement' => 'top',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        StorefrontBanner::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'image_path' => 'storefront-banners/bottom.jpg',
            'placement' => 'bottom',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/storefront-banners?placement=bottom')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('bottom', $response->json('data.0.placement'));
    }

    // TASK-073 — human-confirmed via AskUserQuestion (2026-08-02): a
    // banner's link target can now be a Product (default/legacy), a free
    // URL, or a whitelisted internal path.
    public function test_banner_defaults_to_product_link_type_when_omitted(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        $response = $this->actingAs($admin)->post('/api/v1/storefront-banners', [
            'product_id' => $product->id,
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertCreated();

        $this->assertSame('product', $response->json('data.link_type'));
        $this->assertSame($product->id, $response->json('data.product.id'));
    }

    public function test_banner_can_be_created_with_url_link_type(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->post('/api/v1/storefront-banners', [
            'link_type' => 'url',
            'external_url' => 'https://example.com/promo',
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertCreated();

        $this->assertSame('url', $response->json('data.link_type'));
        $this->assertSame('https://example.com/promo', $response->json('data.external_url'));
        $this->assertNull($response->json('data.product'));
    }

    public function test_banner_can_be_created_with_internal_link_type(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->post('/api/v1/storefront-banners', [
            'link_type' => 'internal',
            'internal_path' => '/academy',
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertCreated();

        $this->assertSame('internal', $response->json('data.link_type'));
        $this->assertSame('/academy', $response->json('data.internal_path'));
    }

    public function test_banner_with_url_link_type_requires_a_valid_url(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/storefront-banners', [
            'link_type' => 'url',
            'external_url' => 'not-a-url',
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('external_url');
    }

    public function test_banner_with_internal_link_type_rejects_a_path_outside_the_whitelist(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->postJson('/api/v1/storefront-banners', [
            'link_type' => 'internal',
            'internal_path' => '/admin/secret',
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('internal_path');
    }

    public function test_banner_with_url_link_type_rejects_a_product_id(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        $this->actingAs($admin)->postJson('/api/v1/storefront-banners', [
            'link_type' => 'url',
            'external_url' => 'https://example.com/promo',
            'product_id' => $product->id,
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_banner_link_type_can_be_switched_on_update(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();
        $banner = StorefrontBanner::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'link_type' => 'product',
            'image_path' => 'storefront-banners/existing.jpg',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->putJson("/api/v1/storefront-banners/{$banner->id}", [
            'link_type' => 'url',
            'external_url' => 'https://example.com/new-promo',
        ])->assertOk();

        $this->assertSame('url', $response->json('data.link_type'));
        $this->assertSame('https://example.com/new-promo', $response->json('data.external_url'));
    }

    public function test_updating_unrelated_fields_does_not_require_resending_link_target(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();
        $banner = StorefrontBanner::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'link_type' => 'product',
            'image_path' => 'storefront-banners/existing.jpg',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson("/api/v1/storefront-banners/{$banner->id}", [
            'title' => 'ชื่อใหม่',
        ])->assertOk()->assertJsonPath('data.title', 'ชื่อใหม่');
    }

    public function test_deleting_a_banner_removes_its_image_from_disk(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        $create = $this->actingAs($admin)->post('/api/v1/storefront-banners', [
            'product_id' => $product->id,
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertCreated();
        $banner = StorefrontBanner::find($create->json('data.id'));
        $path = $banner->image_path;

        $this->actingAs($admin)->delete("/api/v1/storefront-banners/{$banner->id}")->assertNoContent();

        Storage::disk('public')->assertMissing($path);
    }
}
