<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// CLAUDE.md Section 5 rule 5 + Definition of Done: every endpoint must
// have tenant-isolation test cases, cross-tenant access expected to
// 403/404.
class BrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_list_brands_in_their_own_company(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        Brand::factory()->for($company)->create();

        $this->actingAs($agent)
            ->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_agent_cannot_create_a_brand(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson('/api/v1/brands', ['name' => 'New Brand'])
            ->assertForbidden();
    }

    public function test_company_admin_can_create_a_brand_in_their_own_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson('/api/v1/brands', ['name' => 'New Brand'])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $company->id);
    }

    public function test_company_admin_cannot_view_another_companys_brand(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $otherBrand = Brand::factory()->for($otherCompany)->create();

        // TenantScope filters the query used for route-model-binding —
        // a cross-tenant ID never resolves, so this 404s before the
        // Policy even runs (BR-6 rule 5, IDOR guard).
        $this->actingAs($admin)
            ->getJson("/api/v1/brands/{$otherBrand->id}")
            ->assertNotFound();
    }

    public function test_company_admin_cannot_update_another_companys_brand(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $ownCompany->id]);
        $otherBrand = Brand::factory()->for($otherCompany)->create();

        $this->actingAs($admin)
            ->putJson("/api/v1/brands/{$otherBrand->id}", ['name' => 'Hijacked'])
            ->assertNotFound();
    }

    public function test_super_admin_can_create_a_brand_for_any_company(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson('/api/v1/brands', ['name' => 'Cross-company brand', 'company_id' => $company->id])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $company->id);
    }

    /**
     * TASK-202 — the index used to paginate() at the default 15 while every
     * consumer renders `data` and none renders a pager, so brand #16 onward
     * silently did not exist in the UI. A Super Admin's list spans every
     * company (TenantScope does not narrow them), so that ceiling is hit
     * with only a couple of companies.
     */
    public function test_brand_index_is_not_truncated_at_the_pagination_default(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        Brand::factory()->count(20)->for(Company::factory()->create())->create();

        $this->actingAs($superAdmin)
            ->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonCount(20, 'data');
    }

    /**
     * TASK-202 — products_count feeds the "ใช้กับสินค้า N" column, which is
     * what warns an admin BEFORE they click delete that DeletionGuard will
     * refuse (products.brand_id is restrictOnDelete).
     */
    public function test_brand_index_exposes_how_many_products_use_each_brand(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $used = Brand::factory()->for($company)->create();
        $unused = Brand::factory()->for($company)->create();
        Product::factory()->count(2)->for($company)->for($used)->create();

        $data = collect($this->actingAs($admin)->getJson('/api/v1/brands')->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame(2, $data[$used->id]['products_count']);
        $this->assertSame(0, $data[$unused->id]['products_count']);
    }

    /** TASK-205 — brand logo upload, mirroring StorefrontBannerTest's image cases. */
    public function test_company_admin_can_upload_a_brand_logo(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)
            ->post('/api/v1/brands', [
                'name' => 'With logo',
                'logo' => UploadedFile::fake()->image('mark.png'),
            ])
            ->assertCreated();

        $path = $response->json('data.logo_path');
        $this->assertNotNull($path);
        $this->assertNotNull($response->json('data.logo_url'));
        Storage::disk('public')->assertExists($path);
    }

    /** Replacing a logo must not leave the previous file behind. */
    public function test_replacing_a_logo_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $first = $this->actingAs($admin)
            ->post('/api/v1/brands', ['name' => 'Logo brand', 'logo' => UploadedFile::fake()->image('a.png')])
            ->assertCreated()->json('data');

        $second = $this->actingAs($admin)
            // Browsers cannot send multipart on PUT — the frontend spoofs it.
            ->post("/api/v1/brands/{$first['id']}", ['_method' => 'PUT', 'logo' => UploadedFile::fake()->image('b.png')])
            ->assertOk()->json('data');

        $this->assertNotSame($first['logo_path'], $second['logo_path']);
        Storage::disk('public')->assertMissing($first['logo_path']);
        Storage::disk('public')->assertExists($second['logo_path']);
    }

    /** An ordinary rename must never wipe the logo; only remove_logo does. */
    public function test_rename_keeps_the_logo_but_remove_logo_clears_it(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $created = $this->actingAs($admin)
            ->post('/api/v1/brands', ['name' => 'Keep', 'logo' => UploadedFile::fake()->image('a.png')])
            ->assertCreated()->json('data');

        $renamed = $this->actingAs($admin)
            ->putJson("/api/v1/brands/{$created['id']}", ['name' => 'Renamed'])
            ->assertOk()->json('data');
        $this->assertSame($created['logo_path'], $renamed['logo_path']);

        $cleared = $this->actingAs($admin)
            ->putJson("/api/v1/brands/{$created['id']}", ['remove_logo' => true])
            ->assertOk()->json('data');
        $this->assertNull($cleared['logo_path']);
        $this->assertNull($cleared['logo_url']);
        Storage::disk('public')->assertMissing($created['logo_path']);
    }

    /** Section 6 — an SVG is an executable document served off the public disk. */
    public function test_a_non_image_or_svg_logo_is_rejected(): void
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->post('/api/v1/brands', ['name' => 'Bad', 'logo' => UploadedFile::fake()->create('payload.svg', 8, 'image/svg+xml')])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->post('/api/v1/brands', ['name' => 'Bad', 'logo' => UploadedFile::fake()->create('payload.pdf', 8, 'application/pdf')])
            ->assertStatus(422);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/brands')->assertUnauthorized();
    }
}
