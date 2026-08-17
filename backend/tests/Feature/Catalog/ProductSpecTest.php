<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// ADR-007 — admin-editable key-value product spec sheet (BR-7: no fixed taxonomy).
class ProductSpecTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_add_a_spec_row(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->postJson("/api/v1/products/{$product->id}/specs", [
                'spec_group' => 'ความคุ้มครอง', 'spec_key' => 'วงเงินคุ้มครองสูงสุด', 'spec_value' => '1,000,000 บาท',
            ])
            ->assertCreated()
            ->assertJsonPath('data.spec_key', 'วงเงินคุ้มครองสูงสุด');
    }

    public function test_agent_cannot_add_a_spec_row_but_can_view_specs(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);

        $this->actingAs($agent)
            ->postJson("/api/v1/products/{$product->id}/specs", ['spec_key' => 'น้ำหนัก', 'spec_value' => '2 กก.'])
            ->assertForbidden();

        $this->actingAs($admin)->postJson("/api/v1/products/{$product->id}/specs", ['spec_key' => 'น้ำหนัก', 'spec_value' => '2 กก.'])->assertCreated();

        $this->actingAs($agent)->getJson("/api/v1/products/{$product->id}/specs")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_company_admin_can_update_and_delete_a_spec_row(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $spec = ProductSpec::create(['company_id' => $company->id, 'product_id' => $product->id, 'spec_key' => 'อายุที่รับ', 'spec_value' => '20-60 ปี']);

        $this->actingAs($admin)
            ->putJson("/api/v1/product-specs/{$spec->id}", ['spec_value' => '18-65 ปี'])
            ->assertOk()
            ->assertJsonPath('data.spec_value', '18-65 ปี');

        $this->actingAs($admin)->deleteJson("/api/v1/product-specs/{$spec->id}")->assertNoContent();
        $this->assertDatabaseMissing('product_specs', ['id' => $spec->id]);
    }

    public function test_cross_company_admin_cannot_manage_another_companys_specs(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $otherAdmin = User::factory()->companyAdmin()->create(['company_id' => $otherCompany->id]);
        $product = Product::factory()->create(['company_id' => $ownCompany->id]);
        $spec = ProductSpec::create(['company_id' => $ownCompany->id, 'product_id' => $product->id, 'spec_key' => 'k', 'spec_value' => 'v']);

        $this->actingAs($otherAdmin)->putJson("/api/v1/product-specs/{$spec->id}", ['spec_value' => 'x'])->assertNotFound();
        $this->actingAs($otherAdmin)->postJson("/api/v1/products/{$product->id}/specs", ['spec_key' => 'k', 'spec_value' => 'v'])->assertNotFound();
    }
}
