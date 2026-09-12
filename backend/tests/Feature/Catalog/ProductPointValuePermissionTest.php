<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-12 — PV is commission configuration, and the owner's 2026-09-11
 * decision put every commission setting behind Super Admin.
 *
 * It would be easy to read `pv_satang` as just another product attribute
 * sitting next to the price, editable by whoever edits products. It is
 * not: on a PV company it is the number every payout in the plan is a
 * percentage of, and a Company Admin who could move it could set their
 * own agents' commissions without touching a single commission screen.
 *
 * PROHIBITED, NOT SILENTLY STRIPPED. A dropped field shows an admin a
 * saved form and a number that never changed — they would find out weeks
 * later, from a payout. A 422 is rude and correct.
 */
class ProductPointValuePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_may_set_a_point_value(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create(['pv_satang' => null]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$product->id}", ['pv_satang' => 100000])
            ->assertOk();

        $this->assertSame(100000, $product->fresh()->pv_satang);
    }

    public function test_a_super_admin_may_clear_a_point_value_back_to_unset(): void
    {
        // Explicit null is a real edit, not a no-op: it puts the product
        // back into the state the readiness banner warns about, which is
        // the honest thing to do when a PV was entered by mistake. Null
        // and 0 stay different — see the pv_satang migration.
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create(['pv_satang' => 100000]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$product->id}", ['pv_satang' => null])
            ->assertOk();

        $this->assertNull($product->fresh()->pv_satang);
    }

    public function test_a_company_admin_is_refused_rather_than_quietly_ignored(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create(['pv_satang' => 100000]);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $company->id]))
            ->putJson("/api/v1/products/{$product->id}", ['pv_satang' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pv_satang');

        $this->assertSame(100000, $product->fresh()->pv_satang, 'the refused request changed nothing');
    }

    public function test_a_company_admin_may_still_edit_the_rest_of_the_product(): void
    {
        // The control: the guard is on ONE field, not on the endpoint. A
        // Company Admin who could no longer rename their own product would
        // be a regression wearing this change's clothes.
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create(['name' => 'เดิม']);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $company->id]))
            ->putJson("/api/v1/products/{$product->id}", ['name' => 'ใหม่'])
            ->assertOk();

        $this->assertSame('ใหม่', $product->fresh()->name);
    }
}
