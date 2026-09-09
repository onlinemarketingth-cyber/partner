<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 2026-09-09 (human, from production: "Upload รูปแล้ว error" — a 500 on
 * POST /products/11/media, product #11 being GENESENN 1-Year Vital
 * Blueprint, promoted to the platform two days earlier).
 *
 * ── THE SHAPE OF THE BUG ──
 *
 * ADR-040 made `products.company_id` nullable. Four services copy that value
 * onto the rows they create:
 *
 *     'company_id' => $product->company_id,
 *
 * and all four child tables still declared it NOT NULL. So the insert failed
 * at the DATABASE — a 500, not a 422. Nothing the person did was wrong, and
 * the screen could not tell them anything useful.
 *
 * Only the media tab had been tried. Specs, spec attachments and sales
 * materials were the same 500 waiting on the next tab, which is why all four
 * are fixed and tested together: shipping one of four here would teach
 * somebody that the product screen is unreliable, which is a more expensive
 * thing to lose than an afternoon.
 *
 * ── WHY NULL, RATHER THAN THE UPLOADER'S COMPANY ──
 *
 * These four tables hold the product's OWN content. ADR-040 says a platform
 * product is ONE row every company sells; its photographs belong to that row.
 * Stamping the uploading company would scope the photos to one tenant and
 * leave every other company selling a product with no pictures — the
 * copy-per-company model the human rejected on 2026-09-05, coming back in
 * through a side door.
 *
 * ── THE READ SIDE IS HALF THE FIX ──
 *
 * All four models carried plain TenantScope (`where company_id = :own`),
 * which excludes NULL. Making the column nullable without moving to
 * SharedOrTenantScope would have turned the 500 into something worse and
 * quieter: the upload succeeds, and the gallery is empty for everyone but a
 * Super Admin.
 */
class PlatformProductContentTest extends TestCase
{
    use RefreshDatabase;

    private Company $aia;

    private Company $thaiLife;

    private Product $shared;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aia = Company::factory()->create(['name' => 'AIA']);
        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);

        // Exactly what `catalog:promote-products` leaves behind.
        $this->shared = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'name' => 'GENESENN 1-Year Vital Blueprint',
            'price_satang' => 2990000,
            'is_active' => true,
            'commission_plan_type' => 'unilevel',
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function adminOf(Company $company): User
    {
        return User::factory()->companyAdmin()->create(['company_id' => $company->id]);
    }

    /** Switch the shared product on for one company (ADR-040 — never inherited). */
    private function grant(Company $company): void
    {
        CompanyProductSetting::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $company->id,
            'product_id' => $this->shared->id,
            'is_active' => true,
        ]);
    }

    // ── The report ───────────────────────────────────────────────────

    public function test_a_photo_can_be_uploaded_onto_a_platform_product(): void
    {
        // The 500, in one line.
        Storage::fake('local');

        $this->actingAs($this->superAdmin())
            ->postJson("/api/v1/products/{$this->shared->id}/media", [
                'media_type' => 'image',
                'file' => UploadedFile::fake()->image('cover.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.media_type', 'image');

        $media = ProductMedia::withoutGlobalScope(SharedOrTenantScope::class)
            ->where('product_id', $this->shared->id)->firstOrFail();

        $this->assertNull($media->company_id, 'the photo belongs to the product, not to whoever uploaded it');
    }

    public function test_the_file_does_not_land_in_a_path_with_an_empty_segment(): void
    {
        /*
         * "product-media/{$product->company_id}/{$product->id}" with a NULL
         * company yields "product-media//11". Storage drivers disagree about
         * whether they collapse that, so the path a file is written to and the
         * path it is later read from can differ — the upload succeeds and the
         * image 404s afterwards, which is worse than the 500 was.
         */
        Storage::fake('local');

        $this->actingAs($this->superAdmin())
            ->postJson("/api/v1/products/{$this->shared->id}/media", [
                'media_type' => 'image',
                'file' => UploadedFile::fake()->image('cover.jpg'),
            ])
            ->assertCreated();

        $path = ProductMedia::withoutGlobalScope(SharedOrTenantScope::class)
            ->where('product_id', $this->shared->id)->value('file_path');

        $this->assertStringNotContainsString('//', $path);
        $this->assertStringStartsWith("product-media/platform/{$this->shared->id}/", $path);
    }

    public function test_a_spec_a_spec_attachment_and_a_sales_material_all_work_too(): void
    {
        // The three tabs nobody had opened yet on a promoted product.
        Storage::fake('local');
        $actor = $this->superAdmin();

        $this->actingAs($actor)
            ->postJson("/api/v1/products/{$this->shared->id}/specs", ['spec_key' => 'ระยะเวลา', 'spec_value' => '1 ปี'])
            ->assertCreated();

        $this->actingAs($actor)
            ->postJson("/api/v1/products/{$this->shared->id}/spec-attachments", [
                'media_type' => 'image',
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->image('spec.jpg'),
            ])
            ->assertCreated();

        $this->actingAs($actor)
            ->postJson("/api/v1/products/{$this->shared->id}/sales-materials", [
                'source_type' => 'upload',
                'file' => UploadedFile::fake()->create('brochure.pdf', 40, 'application/pdf'),
            ])
            ->assertCreated();
    }

    // ── The read side ────────────────────────────────────────────────

    public function test_a_company_admin_sees_the_shared_products_photos(): void
    {
        /*
         * The quiet failure the scope change prevents. With plain TenantScope
         * this list comes back empty for everyone except a Super Admin: the
         * company sells the product and cannot see a single picture of it.
         */
        Storage::fake('local');
        $this->grant($this->aia);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/v1/products/{$this->shared->id}/media", [
                'media_type' => 'image',
                'file' => UploadedFile::fake()->image('cover.jpg'),
            ])->assertCreated();

        $this->actingAs($this->adminOf($this->aia))
            ->getJson("/api/v1/products/{$this->shared->id}/media")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_one_companys_own_photos_are_still_invisible_to_another(): void
    {
        // BR-6, unchanged. The scope was widened for ownerless rows only.
        Storage::fake('local');
        $ownProduct = Product::factory()->create(['company_id' => $this->aia->id]);

        $this->actingAs($this->adminOf($this->aia))
            ->postJson("/api/v1/products/{$ownProduct->id}/media", [
                'media_type' => 'image',
                'file' => UploadedFile::fake()->image('cover.jpg'),
            ])->assertCreated();

        $this->actingAs($this->adminOf($this->thaiLife))
            ->getJson("/api/v1/products/{$ownProduct->id}/media")
            ->assertNotFound();
    }
}
