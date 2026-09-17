<?php

namespace Tests\Feature\Supplier;

use App\Enums\OrderStatus;
use App\Enums\ShippingStatus;
use App\Enums\SupplierGpMode;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderVoucher;
use App\Models\Product;
use App\Models\SupplierSettlementLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-16 — what a Company Partner can and cannot reach.
 *
 * ── WHY THIS FILE MATTERS MORE THAN THE ARITHMETIC ONES ──
 *
 * Every other role in this system looks INWARD at its own company, and
 * TenantScope enforces that for free — forget a filter and you see nothing.
 * A supplier looks the other way: the orders they need belong to OTHER
 * tenants, so every query here crosses TenantScope deliberately and filters by
 * `products.supplier_company_id` by hand.
 *
 * That inverts the failure mode. A missing filter does not show a supplier too
 * little; it shows them another supplier's orders, complete with our
 * customers' names, phone numbers and home addresses. There is no second line
 * of defence behind the filter, so it gets a test per surface.
 */
class SupplierPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{supplier: Company, partner: User, product: Product} */
    private function supplierWith(string $name = 'Supplier A'): array
    {
        $supplier = Company::factory()->create([
            'name' => $name,
            'is_supplier' => true,
            'supplier_gp_mode' => SupplierGpMode::PercentOfSale->value,
            'supplier_gp_value' => 3000,
            'supplier_release_trigger' => 'on_payment',
        ]);

        $partner = User::factory()->create([
            'company_id' => $supplier->id,
            'role' => 'company_partner',
        ]);

        $product = Product::factory()->create([
            'company_id' => null,
            'supplier_company_id' => $supplier->id,
            'requires_shipping' => true,
        ]);

        return compact('supplier', 'partner', 'product');
    }

    private function paidOrderFor(Product $product, Company $seller, string $customer = 'ลูกค้า ทดสอบ'): Order
    {
        $client = Client::factory()->create(['company_id' => $seller->id, 'name' => $customer]);

        return Order::factory()->create([
            'company_id' => $seller->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'status' => OrderStatus::Paid->value,
            'paid_at' => now(),
            'shipping_recipient_name' => $customer,
            'shipping_phone' => '0812345678',
            'shipping_address' => '99/1 ถนนสีลม กรุงเทพฯ',
        ]);
    }

    // ── The filter ─────────────────────────────────────────────────────

    public function test_a_partner_sees_their_own_products_orders_across_every_selling_company(): void
    {
        /*
         * The point of the arrangement: one supplier, several of our companies
         * selling their goods. A filter written as "my company's orders" would
         * return nothing at all here.
         */
        $a = $this->supplierWith();
        $sellerOne = Company::factory()->create(['name' => 'Seller One']);
        $sellerTwo = Company::factory()->create(['name' => 'Seller Two']);

        $this->paidOrderFor($a['product'], $sellerOne, 'ลูกค้า หนึ่ง');
        $this->paidOrderFor($a['product'], $sellerTwo, 'ลูกค้า สอง');

        $response = $this->actingAs($a['partner'])->getJson('/api/v1/supplier/orders')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_a_partner_never_sees_another_suppliers_orders(): void
    {
        $a = $this->supplierWith('Supplier A');
        $b = $this->supplierWith('Supplier B');
        $seller = Company::factory()->create();

        $this->paidOrderFor($a['product'], $seller, 'ลูกค้าของ A');
        $this->paidOrderFor($b['product'], $seller, 'ลูกค้าของ B');

        $response = $this->actingAs($a['partner'])->getJson('/api/v1/supplier/orders')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('ลูกค้าของ A', $response->json('data.0.customer_name'));
        $this->assertStringNotContainsString('ลูกค้าของ B', $response->getContent());
    }

    public function test_unpaid_orders_are_not_disclosed_at_all(): void
    {
        // An unpaid order is nothing to ship, and its customer's name and
        // address belong to a sale that may never happen.
        $a = $this->supplierWith();
        $seller = Company::factory()->create();

        $order = $this->paidOrderFor($a['product'], $seller);
        $order->forceFill(['status' => OrderStatus::Pending->value, 'paid_at' => null])->save();

        $response = $this->actingAs($a['partner'])->getJson('/api/v1/supplier/orders')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    // ── What the payload may and may not carry ─────────────────────────

    public function test_the_address_is_disclosed_only_for_products_that_ship(): void
    {
        $a = $this->supplierWith();
        $seller = Company::factory()->create();
        $this->paidOrderFor($a['product'], $seller);

        $shipping = $this->actingAs($a['partner'])->getJson('/api/v1/supplier/orders')->assertOk();
        $this->assertSame('99/1 ถนนสีลม กรุงเทพฯ', $shipping->json('data.0.shipping_address'));

        // A service product: the supplier meets the customer at redemption and
        // needs no address, so the keys are ABSENT rather than null.
        $a['product']->forceFill(['requires_shipping' => false])->save();

        $service = $this->actingAs($a['partner'])->getJson('/api/v1/supplier/orders')->assertOk();
        $this->assertArrayNotHasKey('shipping_address', $service->json('data.0'));
        $this->assertArrayNotHasKey('shipping_phone', $service->json('data.0'));
        // The name still comes through — it is what goes on the appointment.
        $this->assertNotNull($service->json('data.0.customer_name'));
    }

    public function test_the_payload_never_carries_our_commercial_data(): void
    {
        /*
         * A supplier with a list of our best-selling agents is a supplier who
         * can approach them directly. Commission and GP are our margin and are
         * derivable from each other.
         */
        $a = $this->supplierWith();
        $seller = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $seller->id, 'first_name' => 'เกรียงยศ']);
        $order = $this->paidOrderFor($a['product'], $seller);
        $order->forceFill(['agent_id' => $agent->id])->save();

        $body = $this->actingAs($a['partner'])->getJson('/api/v1/supplier/orders')->assertOk()->getContent();

        $this->assertStringNotContainsString('เกรียงยศ', $body);
        $this->assertStringNotContainsString('commission', $body);
        $this->assertStringNotContainsString('gp_satang', $body);
        $this->assertStringNotContainsString('client_id', $body);
    }

    public function test_a_partners_own_settlement_list_hides_the_arithmetic_behind_it(): void
    {
        $a = $this->supplierWith();
        $seller = Company::factory()->create();
        $order = $this->paidOrderFor($a['product'], $seller);

        SupplierSettlementLedger::create([
            'supplier_company_id' => $a['supplier']->id,
            'company_id' => $seller->id,
            'order_id' => $order->id,
            'product_id' => $a['product']->id,
            'sale_price_satang_at_time' => 100000,
            'commission_satang_at_time' => 10000,
            'gp_mode_at_time' => SupplierGpMode::PercentOfSale->value,
            'gp_value_at_time' => 3000,
            'gp_satang_at_time' => 30000,
            'amount_satang' => 60000,
            'released_at' => now(),
            'payment_status' => 'pending',
        ]);

        $body = $this->actingAs($a['partner'])->getJson('/api/v1/supplier/settlements')->assertOk();

        $this->assertSame(60000, $body->json('data.0.amount_satang'));
        $this->assertArrayNotHasKey('commission_satang', $body->json('data.0'));
        $this->assertArrayNotHasKey('gp_satang', $body->json('data.0'));
    }

    // ── The wall ───────────────────────────────────────────────────────

    /**
     * 2026-09-17 — THE OTHER DIRECTION, WHICH THE FIRST DRAFT MISSED.
     *
     * Every test in this file asked "can a partner reach things they should
     * not?" and none asked "can anybody else reach the PARTNER's screens?"
     *
     * They could. RestrictScopedRole keeps scoped roles out of other
     * endpoints; it does not keep other roles out of /supplier. The owner
     * found it by opening /supplier/settlements as a Super Admin — the menu
     * wrongly offered it — and getting a 500, because a Super Admin has no
     * company_id and balanceFor(null) is a type error.
     *
     * A crash is the loud version. The quiet version is a Company Admin of a
     * supplier company reading a portal built for somebody else.
     */
    public function test_nobody_but_a_partner_can_reach_the_partner_portal(): void
    {
        $company = Company::factory()->create();

        $actors = [
            'super admin' => User::factory()->superAdmin()->create(),
            'company admin' => User::factory()->companyAdmin()->create(['company_id' => $company->id]),
        ];

        foreach ($actors as $who => $actor) {
            foreach ([
                '/api/v1/supplier/orders',
                '/api/v1/supplier/balance',
                '/api/v1/supplier/settlements',
                '/api/v1/supplier/payouts',
            ] as $path) {
                $this->actingAs($actor)
                    ->getJson($path)
                    ->assertForbidden(); // never 500, and never a page of data
            }
        }
    }

    public function test_a_super_admin_hitting_the_partner_portal_gets_403_not_a_crash(): void
    {
        // Named separately because the SYMPTOM is what was reported. A Super
        // Admin has company_id null, and every query here scopes by it.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/supplier/balance')
            ->assertStatus(403);
    }

    public function test_a_partner_is_refused_everywhere_outside_their_own_prefixes(): void
    {
        /*
         * The allowlist is the security model (RestrictScopedRole): a new
         * endpoint is denied to this role by default. These four are the ones
         * somebody would reach for first.
         */
        $a = $this->supplierWith();

        foreach (['/api/v1/orders', '/api/v1/clients', '/api/v1/commission-withdrawals', '/api/v1/products'] as $path) {
            $this->actingAs($a['partner'])->getJson($path)->assertForbidden();
        }
    }

    public function test_a_partner_cannot_reach_the_screen_that_decides_what_suppliers_are_paid(): void
    {
        /*
         * /supplier-payouts is OURS. It sits one character away from
         * /supplier/payouts, which is theirs, and the prefix match is
         * segment-exact precisely so the two do not collapse into each other.
         */
        $a = $this->supplierWith();

        $this->actingAs($a['partner'])->getJson('/api/v1/supplier-payouts')->assertForbidden();
        $this->actingAs($a['partner'])->postJson('/api/v1/supplier-payouts', [
            'supplier_company_id' => $a['supplier']->id,
        ])->assertForbidden();
    }

    public function test_voucher_staff_are_unaffected_by_the_generalised_wall(): void
    {
        // The middleware was one class per role until this sprint; the
        // refactor must not have moved front-desk staff's boundary.
        $company = Company::factory()->create();
        $staff = User::factory()->create(['company_id' => $company->id, 'role' => 'voucher_staff']);

        $this->actingAs($staff)->getJson('/api/v1/orders')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/v1/supplier/orders')->assertForbidden();
    }

    public function test_an_ordinary_admin_is_not_restricted_by_the_wall_at_all(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson('/api/v1/orders')->assertOk();
    }

    // ── Redeeming a voucher for a product you supply ───────────────────

    public function test_a_partner_can_redeem_a_card_for_a_product_they_supply(): void
    {
        /*
         * THE BUG THIS EXISTS TO PROVE FIXED.
         *
         * The card hangs off an order belonging to the SELLING company, and a
         * partner is never that company. Under the old same-tenant rule this
         * returned 404 for every card a partner would ever be handed — the
         * feature would have shipped completely broken while every line of
         * code looked right.
         */
        $a = $this->supplierWith();
        $seller = Company::factory()->create();
        $order = $this->paidOrderFor($a['product'], $seller);

        $voucher = OrderVoucher::create([
            'order_id' => $order->id,
            'code' => 'ABC123',
            'usage_quota' => 1,
            'used_count' => 0,
        ]);

        $this->actingAs($a['partner'])
            ->getJson("/api/v1/vouchers/{$voucher->code}")
            ->assertOk()
            ->assertJsonPath('data.code', 'ABC123');

        $this->actingAs($a['partner'])
            ->postJson('/api/v1/vouchers/redeem', ['code' => 'ABC123'])
            ->assertOk();

        $this->assertSame(1, $voucher->fresh()->used_count);
    }

    public function test_a_partner_cannot_redeem_a_card_for_somebody_elses_product(): void
    {
        $a = $this->supplierWith('Supplier A');
        $b = $this->supplierWith('Supplier B');
        $seller = Company::factory()->create();

        $voucher = OrderVoucher::create([
            'order_id' => $this->paidOrderFor($b['product'], $seller)->id,
            'code' => 'XYZ789',
            'usage_quota' => 1,
            'used_count' => 0,
        ]);

        $this->actingAs($a['partner'])->getJson('/api/v1/vouchers/XYZ789')->assertNotFound();
        $this->actingAs($a['partner'])->postJson('/api/v1/vouchers/redeem', ['code' => 'XYZ789'])->assertNotFound();
    }

    // ── Shipping ───────────────────────────────────────────────────────

    public function test_a_partner_records_a_shipment_on_their_own_order(): void
    {
        $a = $this->supplierWith();
        $seller = Company::factory()->create();
        $order = $this->paidOrderFor($a['product'], $seller);

        $this->actingAs($a['partner'])
            ->postJson("/api/v1/supplier/orders/{$order->id}/ship", ['tracking_number' => 'TH123456789'])
            ->assertOk()
            ->assertJsonPath('data.tracking_number', 'TH123456789');

        $order->refresh();
        $this->assertSame(ShippingStatus::Shipped, $order->shipping_status);
        $this->assertSame($a['partner']->id, $order->shipped_by_user_id);
        $this->assertNotNull($order->shipped_at);
    }

    public function test_a_partner_cannot_ship_another_suppliers_order(): void
    {
        $a = $this->supplierWith('Supplier A');
        $b = $this->supplierWith('Supplier B');
        $seller = Company::factory()->create();
        $order = $this->paidOrderFor($b['product'], $seller);

        $this->actingAs($a['partner'])
            ->postJson("/api/v1/supplier/orders/{$order->id}/ship", ['tracking_number' => 'TH1'])
            ->assertNotFound();

        $this->assertNull($order->fresh()->tracking_number);
    }

    public function test_a_tracking_number_is_required_because_it_is_the_only_checkable_part(): void
    {
        $a = $this->supplierWith();
        $seller = Company::factory()->create();
        $order = $this->paidOrderFor($a['product'], $seller);

        $this->actingAs($a['partner'])
            ->postJson("/api/v1/supplier/orders/{$order->id}/ship", [])
            ->assertStatus(422);
    }

    public function test_a_second_shipment_is_refused_rather_than_overwriting_the_first(): void
    {
        $a = $this->supplierWith();
        $seller = Company::factory()->create();
        $order = $this->paidOrderFor($a['product'], $seller);

        $this->actingAs($a['partner'])
            ->postJson("/api/v1/supplier/orders/{$order->id}/ship", ['tracking_number' => 'TH-FIRST'])
            ->assertOk();

        $this->actingAs($a['partner'])
            ->postJson("/api/v1/supplier/orders/{$order->id}/ship", ['tracking_number' => 'TH-SECOND'])
            ->assertStatus(422);

        $this->assertSame('TH-FIRST', $order->fresh()->tracking_number);
    }
}
