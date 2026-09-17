<?php

namespace Tests\Feature\Supplier;

use App\Enums\CommissionPlanType;
use App\Enums\PaymentStatus;
use App\Enums\SupplierGpMode;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\SupplierSettlementLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-17 — creating and editing a supplier, on its own screen.
 *
 * ── THE TWO MISTAKES THIS FILE IS THE ANSWER TO ──
 *
 * 1. The supplier feature shipped complete and UNUSABLE. Every column existed,
 *    every service read them, 56 tests passed — and there was no way to make a
 *    supplier except by editing the database, so the chain broke at step one:
 *    no supplier, so the product form's picker was empty, so no product had a
 *    supplier, so there was never anything to pay.
 *
 *    The tests all passed because each one built its own fixture with the
 *    supplier already configured. A fixture never walks the path a person has
 *    to walk. That is the class of gap, not the instance — hence
 *    test_the_whole_setup_path_works_end_to_end, which uses endpoints only.
 *
 * 2. The first fix put the settings on the COMPANY screen as a panel beside
 *    the commission plan. The owner rejected it: a tenant we pay commission TO
 *    and a counterparty we buy goods FROM are different relationships with
 *    money flowing in opposite directions, and editing one on the other's
 *    screen teaches everybody the wrong model of the business.
 *
 *    So the supplier has its own table, its own endpoints and its own screen,
 *    and test_a_supplier_is_not_a_company pins the separation rather than
 *    trusting it to stay true.
 */
class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'คลินิกความงาม ก.',
            'legal_name' => 'บริษัท คลินิกความงาม ก. จำกัด',
            'tax_id' => '0105561234567',
            'contact_name' => 'คุณสมชาย',
            'contact_phone' => '0812345678',
            'contact_email' => 'somchai@example.com',
            'gp_mode' => SupplierGpMode::PercentOfSale->value,
            'gp_value' => 3000,
            'release_trigger' => 'on_payment',
            'wht_rate' => 300,
            'payout_bank_name' => 'ธนาคารกสิกรไทย',
            'payout_bank_account_number' => '123-4-56789-0',
            'payout_bank_account_name' => 'บริษัท คลินิกความงาม ก. จำกัด',
        ], $overrides);
    }

    // ── The setup path ─────────────────────────────────────────────────

    public function test_the_whole_setup_path_works_end_to_end(): void
    {
        /*
         * The test that would have caught the original gap: walk it the way a
         * person does, through endpoints, with no fixture shortcuts anywhere.
         *
         * Every step here is a screen somebody has to find. If any one of them
         * has no endpoint behind it, this fails — which is the whole point,
         * because the feature that shipped had five working steps and a
         * missing first one, and every unit test passed.
         */
        $admin = User::factory()->superAdmin()->create();

        // 1. create the supplier
        $supplierId = $this->actingAs($admin)
            ->postJson('/api/v1/suppliers', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.terms_complete', true)
            ->json('data.id');

        // 2. it appears on the management list…
        $this->actingAs($admin)
            ->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonPath('data.0.id', $supplierId);

        /*
         * 3. …and a platform product can be listed against it.
         *
         * `is_platform` rather than `company_id: null`: a supplied product is
         * a PLATFORM product by rule (HandlesSupplierTerms rule 2), because a
         * product owned by one company is sold by that company alone and there
         * is nobody for a supply arrangement to sit between.
         */
        $brand = Brand::factory()->create(['company_id' => null]);
        $category = ProductCategory::factory()->create(['company_id' => null]);

        $productId = $this->actingAs($admin)
            ->postJson('/api/v1/products', [
                'is_platform' => true,
                'brand_id' => $brand->id,
                'category_id' => $category->id,
                'name' => 'คอร์สดูแลผิว',
                'price_satang' => 250000,
                'commission_plan_type' => CommissionPlanType::Unilevel->value,
                'supplier_id' => $supplierId,
            ])
            ->assertCreated()
            ->json('data.id');

        // 4. the product shows up on the supplier's own tab
        $this->actingAs($admin)
            ->getJson("/api/v1/suppliers/{$supplierId}/products")
            ->assertOk()
            ->assertJsonPath('data.0.id', $productId)
            // Inherited, not overridden — the question this tab answers.
            ->assertJsonPath('data.0.gp_is_override', false)
            ->assertJsonPath('data.0.gp_value', 3000);

        // 5. a partner login can be created against the supplier
        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'supplier_id' => $supplierId,
                'first_name' => 'คู่ค้า',
                'last_name' => 'ทดสอบ',
                'email' => 'partner@example.com',
                'password' => 'Str0ng!Passw0rd#2569',
                'role' => 'company_partner',
            ])
            ->assertCreated();

        // 6. and it is listed on the supplier's accounts tab
        $this->actingAs($admin)
            ->getJson("/api/v1/suppliers/{$supplierId}/users")
            ->assertOk()
            ->assertJsonPath('data.0.email', 'partner@example.com')
            ->assertJsonPath('data.0.is_partner_role', true);

        // 7. that login works, and lands on its own screen rather than a 403
        $partner = User::withoutGlobalScopes()->where('email', 'partner@example.com')->firstOrFail();

        $this->assertNull($partner->company_id, 'a partner must not carry a tenant');
        $this->assertSame($supplierId, $partner->supplier_id);

        $this->actingAs($partner)->getJson('/api/v1/supplier/orders')->assertOk();
    }

    // ── The separation the owner asked for ─────────────────────────────

    public function test_a_supplier_is_not_a_company(): void
    {
        /*
         * Pinned rather than assumed. The whole rework exists because these
         * two were briefly the same row, and the cheapest way for that to come
         * back is somebody "reusing" the companies table for a supplier
         * because it already has a name and a bank account.
         */
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->postJson('/api/v1/suppliers', $this->payload())->assertCreated();

        $this->assertDatabaseCount('suppliers', 1);
        // Creating a supplier creates no tenant, and therefore no commission
        // plan, no ranks and nothing on the company screen.
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_a_company_admin_cannot_reach_suppliers_at_all(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $supplier = Supplier::factory()->create();

        $this->actingAs($admin)->getJson('/api/v1/suppliers')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/suppliers', $this->payload())->assertForbidden();
        $this->actingAs($admin)->putJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/v1/suppliers/{$supplier->id}")->assertForbidden();
    }

    public function test_a_company_admin_cannot_mint_a_partner_login(): void
    {
        /*
         * A partner login reads orders belonging to tenants other than the
         * creator's. A Company Admin issuing one would be handing somebody a
         * window onto every company that sells that supplier's products.
         */
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $supplier = Supplier::factory()->withTerms()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'supplier_id' => $supplier->id,
                'first_name' => 'คู่ค้า',
                'last_name' => 'ทดสอบ',
                'email' => 'partner@example.com',
                'password' => 'Str0ng!Passw0rd#2569',
                'role' => 'company_partner',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_a_partner_login_carries_a_supplier_and_never_a_company(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->withTerms()->create();

        // Naming both is refused rather than quietly preferring one — the
        // ambiguity is the bug, not the extra field.
        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'supplier_id' => $supplier->id,
                'company_id' => $company->id,
                'first_name' => 'คู่ค้า',
                'last_name' => 'ทดสอบ',
                'email' => 'partner@example.com',
                'password' => 'Str0ng!Passw0rd#2569',
                'role' => 'company_partner',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');

        // And the reverse: an ordinary role may not carry a supplier.
        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'company_id' => $company->id,
                'supplier_id' => $supplier->id,
                'first_name' => 'พนักงาน',
                'last_name' => 'ทดสอบ',
                'email' => 'staff@example.com',
                'password' => 'Str0ng!Passw0rd#2569',
                'role' => 'agent',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');
    }

    public function test_a_partner_login_is_refused_against_an_inactive_supplier(): void
    {
        // Otherwise the account signs in successfully and sees nothing, which
        // the person holding it cannot tell from a bug.
        $admin = User::factory()->superAdmin()->create();
        $supplier = Supplier::factory()->withTerms()->inactive()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'supplier_id' => $supplier->id,
                'first_name' => 'คู่ค้า',
                'last_name' => 'ทดสอบ',
                'email' => 'partner@example.com',
                'password' => 'Str0ng!Passw0rd#2569',
                'role' => 'company_partner',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');
    }

    // ── The deal terms ─────────────────────────────────────────────────

    public function test_a_supplier_saves_with_no_deal_terms_at_all(): void
    {
        /*
         * A deal negotiated in stages is the normal case. Refusing to save
         * until every term is agreed does not produce a complete record — it
         * produces a note in somebody's notebook.
         */
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/suppliers', ['name' => 'คู่ค้าใหม่'])
            ->assertCreated()
            ->assertJsonPath('data.terms_complete', false)
            ->assertJsonPath('data.missing_terms', ['gp', 'release_trigger', 'bank']);
    }

    public function test_gp_mode_and_value_must_be_set_together(): void
    {
        // A mode with no value falls through to nothing and a percentage read
        // as an amount is wrong by a factor of a thousand while still looking
        // like a number.
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/suppliers', $this->payload(['gp_value' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('gp_value');
    }

    public function test_a_percentage_gp_above_one_hundred_percent_is_refused(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/suppliers', $this->payload(['gp_value' => 10001]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('gp_value');
    }

    public function test_a_fixed_gp_may_exceed_ten_thousand_because_it_is_satang(): void
    {
        // 10001 basis points is 100.01%, which is nonsense. 10001 satang is
        // 100.01 baht, which is an ordinary margin. Same column, same number,
        // and the mode is what decides — which is why the ceiling is applied
        // per mode rather than to the column.
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/suppliers', $this->payload([
                'gp_mode' => SupplierGpMode::FixedPerUnit->value,
                'gp_value' => 50000,
            ]))
            ->assertCreated();
    }

    public function test_an_edit_may_touch_one_section_without_clearing_the_others(): void
    {
        /*
         * The supplier form has four panels. Somebody saving the bank details
         * must not be able to blank the deal terms by not sending them.
         */
        $admin = User::factory()->superAdmin()->create();
        $supplier = Supplier::factory()->withTerms()->create();

        $this->actingAs($admin)
            ->putJson("/api/v1/suppliers/{$supplier->id}", [
                'payout_bank_account_number' => '999-9-99999-9',
            ])
            ->assertOk()
            ->assertJsonPath('data.payout_bank_account_number', '999-9-99999-9')
            ->assertJsonPath('data.gp_value', 3000)
            ->assertJsonPath('data.terms_complete', true);
    }

    public function test_a_supplier_we_still_owe_may_be_deactivated_but_not_deleted(): void
    {
        /*
         * Deactivating is how a deal ENDS, and it has to stay possible while
         * the last invoice is outstanding — refusing it just means the flag
         * never gets set and the supplier stays on the current list forever.
         *
         * DELETING is the one that loses money: the payout screen lists
         * suppliers from this table, so a deleted row takes the debt off the
         * only screen that would have shown it.
         */
        $admin = User::factory()->superAdmin()->create();
        $supplier = Supplier::factory()->withTerms()->create();

        $this->ledgerRowFor($supplier);

        $this->actingAs($admin)
            ->putJson("/api/v1/suppliers/{$supplier->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/suppliers/{$supplier->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'deleted_at' => null]);
    }

    public function test_a_deactivated_supplier_we_owe_still_appears_on_the_payout_screen(): void
    {
        // The other half of the rule above. Ending a deal does not end a debt,
        // and a supplier that vanishes from this screen is a debt nobody sees.
        $admin = User::factory()->superAdmin()->create();
        $supplier = Supplier::factory()->withTerms()->inactive()->create();

        $this->ledgerRowFor($supplier, 70000);

        $this->actingAs($admin)
            ->getJson('/api/v1/supplier-payouts')
            ->assertOk()
            ->assertJsonPath('data.0.supplier_id', $supplier->id)
            ->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('data.0.payable_satang', 70000);
    }

    public function test_a_supplier_with_products_cannot_be_deleted(): void
    {
        // The FK nulls rather than cascades, so deleting would leave live
        // catalogue entries that nobody can be paid for, silently.
        $admin = User::factory()->superAdmin()->create();
        $supplier = Supplier::factory()->withTerms()->create();

        Product::factory()->create(['company_id' => null, 'supplier_id' => $supplier->id]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/suppliers/{$supplier->id}")
            ->assertStatus(422);
    }

    public function test_a_supplier_nobody_has_used_can_be_deleted(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $supplier = Supplier::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/suppliers/{$supplier->id}")
            ->assertOk();

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
    }

    public function test_the_list_can_be_searched_and_filtered(): void
    {
        $admin = User::factory()->superAdmin()->create();

        Supplier::factory()->create(['name' => 'คลินิก ก.']);
        Supplier::factory()->create(['name' => 'ร้าน ข.']);
        Supplier::factory()->inactive()->create(['name' => 'คลินิก ค.']);

        $this->actingAs($admin)
            ->getJson('/api/v1/suppliers?search='.urlencode('คลินิก'))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin)
            ->getJson('/api/v1/suppliers?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // No filter means ALL, not "active" — `boolean()` on a missing
        // parameter reads false, which would hide every working supplier.
        $this->actingAs($admin)
            ->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_the_list_shows_what_each_supplier_is_owed(): void
    {
        // On the LIST, not one click in: the question this screen gets opened
        // for is "who are we behind with".
        $admin = User::factory()->superAdmin()->create();
        $supplier = Supplier::factory()->withTerms()->create();

        $this->ledgerRowFor($supplier, 45000);

        $this->actingAs($admin)
            ->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonPath('data.0.payable_satang', 45000);
    }

    /** A released, unpaid settlement row for this supplier. */
    private function ledgerRowFor(Supplier $supplier, int $amount = 50000): SupplierSettlementLedger
    {
        $seller = Company::factory()->create();
        $product = Product::factory()->create(['company_id' => null, 'supplier_id' => $supplier->id]);
        $order = Order::factory()->create([
            'company_id' => $seller->id,
            'product_id' => $product->id,
            'amount_satang' => $amount,
        ]);

        return SupplierSettlementLedger::create([
            'supplier_id' => $supplier->id,
            'company_id' => $seller->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sale_price_satang_at_time' => $amount,
            'commission_satang_at_time' => 0,
            'gp_mode_at_time' => SupplierGpMode::PercentOfSale->value,
            'gp_value_at_time' => 3000,
            'gp_satang_at_time' => 0,
            'wht_rate_at_time' => null,
            'amount_satang' => $amount,
            'released_at' => now(),
            'payment_status' => PaymentStatus::Pending->value,
        ]);
    }
}
