<?php

namespace Tests\Feature\Supplier;

use App\Enums\PaymentStatus;
use App\Enums\SupplierGpMode;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\SupplierSettlementLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-17 — turning a company INTO a supplier.
 *
 * ── THE MISTAKE THIS FILE IS THE ANSWER TO ──
 *
 * The supplier feature shipped complete and unusable. Every column existed,
 * every service read them, 56 tests passed — and there was no way to set
 * `is_supplier` except by editing the database, so the chain broke at step
 * one: no supplier company, so the product form's supplier picker was empty,
 * so no product had a supplier, so there was never anything to pay.
 *
 * The tests all passed because every one of them built its own fixture with
 * `Company::factory()->create(['is_supplier' => true])`. A fixture never walks
 * the path a person has to walk. That is the class of gap, not the instance.
 */
class SupplierTermsTest extends TestCase
{
    use RefreshDatabase;

    private function terms(array $overrides = []): array
    {
        return array_merge([
            'is_supplier' => true,
            'supplier_gp_mode' => SupplierGpMode::PercentOfSale->value,
            'supplier_gp_value' => 3000,
            'supplier_release_trigger' => 'on_payment',
            'supplier_min_withdrawal_satang' => null,
            'supplier_wht_rate' => null,
            'supplier_payout_bank_name' => 'ธนาคารกสิกรไทย',
            'supplier_payout_bank_account_number' => '123-4-56789-0',
            'supplier_payout_bank_account_name' => 'บริษัท คู่ค้า จำกัด',
        ], $overrides);
    }

    public function test_a_super_admin_can_turn_a_company_into_a_supplier(): void
    {
        $company = Company::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/supplier-payouts/{$company->id}/terms", $this->terms())
            ->assertOk()
            ->assertJsonPath('data.is_supplier', true)
            ->assertJsonPath('data.supplier_gp_value', 3000);

        $this->assertTrue((bool) $company->fresh()->is_supplier);
    }

    public function test_the_whole_setup_path_works_end_to_end(): void
    {
        /*
         * The test that would have caught the gap: walk it the way a person
         * does, using only endpoints, with no fixture shortcuts.
         */
        $admin = User::factory()->superAdmin()->create();
        $company = Company::factory()->create();

        // 1. make it a supplier
        $this->actingAs($admin)
            ->putJson("/api/v1/supplier-payouts/{$company->id}/terms", $this->terms())
            ->assertOk();

        // 2. it now appears on the payout screen, which is also what the
        //    product form reads to populate its supplier picker
        $this->actingAs($admin)
            ->getJson('/api/v1/supplier-payouts')
            ->assertOk()
            ->assertJsonPath('data.0.supplier_company_id', $company->id)
            ->assertJsonPath('data.0.terms_complete', true);

        // 3. a partner login can be created against it
        $this->actingAs($admin)
            ->postJson('/api/v1/users', [
                'company_id' => $company->id,
                'first_name' => 'คู่ค้า',
                'last_name' => 'ทดสอบ',
                'email' => 'partner@example.com',
                'password' => 'Str0ng!Passw0rd#2569',
                'role' => 'company_partner',
            ])
            ->assertCreated();
    }

    public function test_a_partner_account_is_refused_until_the_company_is_a_supplier(): void
    {
        // The step that would otherwise be discovered as a mystery 422 after
        // typing a password. The message names the fix.
        $company = Company::factory()->create(['is_supplier' => false]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/users', [
                'company_id' => $company->id,
                'first_name' => 'คู่ค้า',
                'last_name' => 'ทดสอบ',
                'email' => 'nope@example.com',
                'password' => 'Str0ng!Passw0rd#2569',
                'role' => 'company_partner',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_gp_mode_and_value_must_be_set_together(): void
    {
        // A mode with no value falls through to whatever else is set, and a
        // percentage read as an amount is wrong by a factor of a thousand
        // while still looking like a number.
        $company = Company::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/supplier-payouts/{$company->id}/terms", $this->terms([
                'supplier_gp_value' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_gp_value');
    }

    public function test_a_percentage_gp_above_one_hundred_percent_is_refused(): void
    {
        $company = Company::factory()->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/supplier-payouts/{$company->id}/terms", $this->terms([
                'supplier_gp_value' => 12000, // 120%
            ]))
            ->assertStatus(422);
    }

    public function test_a_supplier_we_still_owe_cannot_be_un_flagged(): void
    {
        /*
         * The payout screen lists suppliers BY this flag. Clearing it hides a
         * company we owe money to, with the debt fully intact and no screen
         * showing it — the kind of disappearance nobody notices until the
         * supplier telephones.
         */
        $company = Company::factory()->create(['is_supplier' => true]);
        $product = Product::factory()->create(['company_id' => null, 'supplier_company_id' => $company->id]);
        $order = Order::factory()->create(['product_id' => $product->id]);

        SupplierSettlementLedger::create([
            'supplier_company_id' => $company->id,
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sale_price_satang' => 100000,
            'sale_price_satang_at_time' => 100000,
            'commission_satang_at_time' => 0,
            'gp_mode_at_time' => SupplierGpMode::PercentOfSale->value,
            'gp_value_at_time' => 3000,
            'gp_satang_at_time' => 30000,
            'amount_satang' => 70000,
            'released_at' => now(),
            'payment_status' => PaymentStatus::Pending->value,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/supplier-payouts/{$company->id}/terms", $this->terms(['is_supplier' => false]))
            ->assertStatus(422);

        $this->assertTrue((bool) $company->fresh()->is_supplier);
    }

    public function test_the_payout_bank_is_not_the_customer_payment_bank(): void
    {
        /*
         * Two accounts on purpose. `payment_bank_*` is where this company
         * takes CUSTOMER money; reusing it would have meant a Super Admin
         * editing another tenant's customer-facing bank details in order to
         * pay a supplier.
         */
        $company = Company::factory()->create([
            'payment_bank_name' => 'บัญชีรับเงินลูกค้า',
            'payment_bank_account_number' => '999-9-99999-9',
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/supplier-payouts/{$company->id}/terms", $this->terms())
            ->assertOk();

        $company->refresh();
        $this->assertSame('ธนาคารกสิกรไทย', $company->supplier_payout_bank_name);
        // Untouched.
        $this->assertSame('บัญชีรับเงินลูกค้า', $company->payment_bank_name);
        $this->assertSame('999-9-99999-9', $company->payment_bank_account_number);
    }

    public function test_only_a_super_admin_may_set_these_terms(): void
    {
        // Every field here decides money. A Company Admin setting their own
        // company's GP would be setting our margin on their own sales.
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)
            ->putJson("/api/v1/supplier-payouts/{$company->id}/terms", $this->terms())
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson("/api/v1/supplier-payouts/{$company->id}/terms")
            ->assertForbidden();
    }
}
