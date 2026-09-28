<?php

namespace Tests\Feature\Supplier;

use App\Enums\CommissionPlanType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShippingStatus;
use App\Enums\SupplierGpMode;
use App\Enums\SupplierReleaseTrigger;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\SupplierPlatformSetting;
use App\Models\SupplierSettlementLedger;
use App\Models\SupplierWithdrawalRequest;
use App\Models\User;
use App\Services\Commission\CommissionReversalService;
use App\Services\Supplier\SupplierPayoutService;
use App\Services\Supplier\SupplierSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-048 (2026-09-27) — the owner's four answers about suppliers, as tests.
 *
 *   1. Suppliers do not request withdrawals themselves.     (SupplierPayoutTest)
 *   2. A refund after we paid the supplier: "หักครั้งถัดไป".
 *   3. Commission after the sale is not taken from the supplier — the amount
 *      is fixed when the sale is recorded.                  (SupplierSettlementTest)
 *   4. "ผู้รับกดรับสินค้า … ไม่กดรับเกิน 15 วันหลังคู่ค้ากดจัดส่ง พึงเบิกเงินได้":
 *      the agent or the customer confirms receipt; if neither does within the
 *      platform window after shipping, the system does.
 *
 * Plus the correctness fixes the 2026-09-27 audit found.
 */
class SupplierReceiptAndRefundTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Company $seller;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::factory()->create([
            'gp_mode' => SupplierGpMode::PercentOfSale->value,
            'gp_value' => 3000,
            'release_trigger' => SupplierReleaseTrigger::OnDelivered->value,
            'payout_bank_name' => 'ธนาคารกสิกรไทย',
            'payout_bank_account_number' => '123-4-56789-0',
            'payout_bank_account_name' => 'บริษัท ซัพพลายเออร์ จำกัด',
        ]);
        $this->seller = Company::factory()->create();
        $this->agent = User::factory()->agent()->create(['company_id' => $this->seller->id]);
    }

    /** A paid order for a shipped product, with its supplier row recorded. */
    private function sale(int $price = 100000, bool $shipping = true): Order
    {
        $product = Product::factory()->create([
            'company_id' => null,
            'supplier_id' => $this->supplier->id,
            'requires_shipping' => $shipping,
            'price_satang' => $price,
        ]);
        $client = Client::factory()->create(['company_id' => $this->seller->id, 'referring_agent_id' => $this->agent->id]);

        $order = Order::factory()->create([
            'company_id' => $this->seller->id,
            'client_id' => $client->id,
            'agent_id' => $this->agent->id,
            'product_id' => $product->id,
            'amount_satang' => $price,
            'status' => OrderStatus::Paid->value,
            'paid_at' => now(),
            'shipping_status' => ShippingStatus::Pending->value,
        ]);

        app(SupplierSettlementService::class)->recordForOrder($order);

        return $order->fresh();
    }

    private function saleRow(Order $order): SupplierSettlementLedger
    {
        return SupplierSettlementLedger::where('order_id', $order->id)->where('entry_kind', 'sale')->firstOrFail();
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function partner(): User
    {
        return User::factory()->create(['company_id' => null, 'supplier_id' => $this->supplier->id, 'role' => 'company_partner']);
    }

    private function ship(Order $order): void
    {
        $this->actingAs($this->partner())
            ->postJson("/api/v1/supplier/orders/{$order->id}/ship", ['tracking_number' => 'TH123'])
            ->assertOk();
    }

    private function payouts(): SupplierPayoutService
    {
        return app(SupplierPayoutService::class);
    }

    /* ── 4. Receipt releases the money, shipping does not ─────────────── */

    public function test_the_suppliers_own_shipped_click_no_longer_releases_their_money(): void
    {
        $order = $this->sale();

        $this->ship($order);

        $this->assertSame(ShippingStatus::Shipped, $order->fresh()->shipping_status);
        $this->assertNull($this->saleRow($order)->released_at, 'the payee reporting the event that pays them');
    }

    public function test_the_selling_agent_confirms_receipt_and_that_releases_it(): void
    {
        $order = $this->sale();
        $this->ship($order);

        $this->actingAs($this->agent)->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.permissions.confirm_receipt', true);

        $this->actingAs($this->agent)->postJson("/api/v1/orders/{$order->id}/receipt")
            ->assertOk()
            ->assertJsonPath('data.receipt_confirmed_via', 'agent');

        $this->assertNotNull($this->saleRow($order)->released_at);
        $this->assertSame(ShippingStatus::Delivered, $order->fresh()->shipping_status);
        $log = AuditLog::where('action', 'order.receipt_confirmed')->where('auditable_id', $order->id)->firstOrFail();
        $this->assertSame('agent', $log->new_values['via']);
        $this->assertSame($this->agent->id, $log->actor_user_id);
    }

    public function test_the_customer_confirms_from_the_link_they_paid_on(): void
    {
        $order = $this->sale();
        $this->ship($order);

        $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('data.can_confirm_receipt', true)
            ->assertJsonPath('data.tracking_number', 'TH123');

        $this->postJson("/api/v1/pay/{$order->public_token}/receipt")
            ->assertOk()
            ->assertJsonPath('data.can_confirm_receipt', false);

        $this->assertSame('customer', $order->fresh()->receipt_confirmed_via);
        $this->assertNotNull($this->saleRow($order)->released_at);

        // Whoever is first counts; the second is refused, not repeated.
        $this->actingAs($this->agent)->postJson("/api/v1/orders/{$order->id}/receipt")->assertStatus(422);
    }

    public function test_receipt_cannot_be_confirmed_before_the_supplier_ships(): void
    {
        $order = $this->sale();

        $this->postJson("/api/v1/pay/{$order->public_token}/receipt")->assertStatus(422);
        $this->actingAs($this->agent)->postJson("/api/v1/orders/{$order->id}/receipt")->assertStatus(422);

        $this->assertNull($order->fresh()->received_at);
    }

    public function test_only_the_selling_agent_may_confirm_not_another_agent_or_an_admin(): void
    {
        $order = $this->sale();
        $this->ship($order);

        $otherAgent = User::factory()->agent()->create(['company_id' => $this->seller->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->seller->id]);
        $foreignAgent = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($otherAgent)->postJson("/api/v1/orders/{$order->id}/receipt")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/orders/{$order->id}/receipt")->assertForbidden();
        // BR-6 — another tenant does not even see the order.
        $this->assertContains(
            $this->actingAs($foreignAgent)->postJson("/api/v1/orders/{$order->id}/receipt")->status(),
            [403, 404],
        );

        $this->assertNull($order->fresh()->received_at);
    }

    /* ── 4. …or the window passes ─────────────────────────────────────── */

    public function test_nobody_confirming_for_the_window_after_shipping_confirms_it_automatically(): void
    {
        $order = $this->sale();
        $this->ship($order);

        $this->travel(14)->days();
        $this->artisan('suppliers:auto-confirm-receipts')->assertSuccessful();
        $this->assertNull($order->fresh()->received_at, 'day 14 of a 15-day window');

        $this->travel(1)->days();
        $this->artisan('suppliers:auto-confirm-receipts')->assertSuccessful();

        $this->assertSame('auto', $order->fresh()->receipt_confirmed_via);
        $this->assertNotNull($this->saleRow($order)->released_at);
    }

    public function test_the_window_is_the_platform_setting_not_a_constant(): void
    {
        SupplierPlatformSetting::query()->update(['auto_receive_days' => 3]);
        $order = $this->sale();
        $this->ship($order);

        $this->travel(3)->days();
        $this->artisan('suppliers:auto-confirm-receipts')->assertSuccessful();

        $this->assertNotNull($order->fresh()->received_at);
    }

    public function test_with_no_setting_row_nothing_is_released_on_a_guessed_schedule(): void
    {
        SupplierPlatformSetting::query()->delete();
        $order = $this->sale();
        $this->ship($order);

        $this->travel(400)->days();
        $this->artisan('suppliers:auto-confirm-receipts')->assertSuccessful();

        $this->assertNull($order->fresh()->received_at);
    }

    public function test_a_closed_company_s_parcels_wait_like_its_other_money_jobs(): void
    {
        $order = $this->sale();
        $this->ship($order);
        $this->seller->update(['is_active' => false]);

        $this->travel(20)->days();
        $this->artisan('suppliers:auto-confirm-receipts')->assertSuccessful();

        $this->assertNull($order->fresh()->received_at);
    }

    public function test_raising_a_payout_does_not_wait_for_the_scheduler(): void
    {
        // Production has not always had its scheduler running.
        $order = $this->sale();
        $this->ship($order);
        $this->travel(16)->days();

        $request = $this->payouts()->open($this->supplier, $this->superAdmin());

        $this->assertSame(70000, $request->gross_satang); // 100,000 − 30% GP
        $this->assertSame('auto', $order->fresh()->receipt_confirmed_via);
    }

    public function test_the_window_is_edited_by_a_super_admin_only_and_audited(): void
    {
        $owner = $this->superAdmin();

        $this->actingAs($owner)->getJson('/api/v1/supplier-settings')
            ->assertOk()->assertJsonPath('data.auto_receive_days', 15);
        $this->actingAs($owner)->putJson('/api/v1/supplier-settings', ['auto_receive_days' => 0])->assertStatus(422);
        $this->actingAs($owner)->putJson('/api/v1/supplier-settings', ['auto_receive_days' => 10])
            ->assertOk()->assertJsonPath('data.auto_receive_days', 10);

        $log = AuditLog::where('action', 'supplier_settings.updated')->firstOrFail();
        $this->assertSame(15, $log->old_values['auto_receive_days']);
        $this->assertSame(10, $log->new_values['auto_receive_days']);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->seller->id]);
        $this->actingAs($admin)->putJson('/api/v1/supplier-settings', ['auto_receive_days' => 1])->assertForbidden();
        $this->actingAs($this->partner())->putJson('/api/v1/supplier-settings', ['auto_receive_days' => 1])->assertForbidden();
    }

    /* ── 2. Refunds: "หักครั้งถัดไป" ───────────────────────────────────── */

    private function refund(Order $order): void
    {
        app(CommissionReversalService::class)->refundOrder($order, $this->superAdmin(), 'ลูกค้าขอคืนเงิน');
    }

    public function test_a_refund_after_we_paid_the_supplier_comes_off_their_next_payout(): void
    {
        $this->supplier->update(['release_trigger' => SupplierReleaseTrigger::OnPayment->value]);
        $refunded = $this->sale(100000);
        $first = $this->payouts()->open($this->supplier, $this->superAdmin());
        $this->payouts()->markTransferred($first, $this->superAdmin(), 'REF-1');

        $this->refund($refunded);

        $refundRow = SupplierSettlementLedger::where('order_id', $refunded->id)->where('entry_kind', 'refund')->firstOrFail();
        $this->assertSame(-70000, $refundRow->amount_satang);
        $this->assertNotNull($refundRow->released_at);
        $this->assertSame(PaymentStatus::Paid, $this->saleRow($refunded)->payment_status, 'the sale row is never edited');

        $this->sale(200000); // the next sale: 140,000 owed
        $next = $this->payouts()->open($this->supplier, $this->superAdmin());

        $this->assertSame(70000, $next->gross_satang, '140,000 − 70,000 refunded');
    }

    public function test_a_refund_while_the_sale_sits_in_an_untransferred_payout_comes_off_the_next_one(): void
    {
        $this->supplier->update(['release_trigger' => SupplierReleaseTrigger::OnPayment->value]);
        $order = $this->sale(100000);
        $raised = $this->payouts()->open($this->supplier, $this->superAdmin());

        $this->refund($order);

        $this->assertSame(-70000, $this->payouts()->balanceFor($this->supplier)['payable_satang']);
        $this->payouts()->markTransferred($raised, $this->superAdmin(), 'REF-1');
        $this->sale(200000);

        $this->assertSame(70000, $this->payouts()->open($this->supplier, $this->superAdmin())->gross_satang);
    }

    public function test_a_refund_before_the_money_was_released_never_becomes_payable(): void
    {
        $order = $this->sale(100000); // on_delivered, not shipped

        $this->refund($order);

        $balance = $this->payouts()->balanceFor($this->supplier);
        $this->assertSame(0, $balance['unreleased_satang'], 'the pair nets to zero while waiting');
        $this->assertSame(0, $balance['payable_satang']);

        // A refunded order is not received into anybody's payout.
        $order->forceFill(['shipping_status' => ShippingStatus::Shipped->value, 'shipped_at' => now()])->save();
        $this->travel(30)->days();
        $this->artisan('suppliers:auto-confirm-receipts')->assertSuccessful();
        $this->assertNull($order->fresh()->received_at);
    }

    public function test_the_refund_row_is_written_once_and_audited_with_the_order(): void
    {
        $this->supplier->update(['release_trigger' => SupplierReleaseTrigger::OnPayment->value]);
        $order = $this->sale(100000);

        $this->refund($order);
        $again = app(SupplierSettlementService::class)->reverseForRefund($order->fresh());

        $this->assertSame(1, SupplierSettlementLedger::where('order_id', $order->id)->where('entry_kind', 'refund')->count());
        $this->assertSame(-70000, $again->amount_satang);
        $log = AuditLog::where('action', 'order.refunded')->where('auditable_id', $order->id)->firstOrFail();
        $this->assertSame(-70000, $log->new_values['supplier_satang_reversed']);
    }

    public function test_a_refund_lowers_the_tax_base_at_its_own_rate(): void
    {
        $this->supplier->update(['release_trigger' => SupplierReleaseTrigger::OnPayment->value, 'wht_rate' => 300]);
        $first = $this->sale(100000);
        $paid = $this->payouts()->open($this->supplier, $this->superAdmin());
        $this->payouts()->markTransferred($paid, $this->superAdmin());
        $this->refund($first);
        $this->sale(200000);

        $next = $this->payouts()->open($this->supplier, $this->superAdmin());

        // Base 140,000 − 70,000 = 70,000; 3% of it.
        $this->assertSame(2100, $next->wht_satang);
    }

    /* ── The audit's fixes ─────────────────────────────────────────────── */

    public function test_changing_a_deals_trigger_does_not_strand_rows_sold_under_the_old_one(): void
    {
        $this->supplier->update(['release_trigger' => SupplierReleaseTrigger::OnRedeemed->value]);
        $order = $this->sale(100000, shipping: false);
        $this->supplier->update(['release_trigger' => SupplierReleaseTrigger::OnPayment->value]);

        $released = app(SupplierSettlementService::class)->releaseFor($order, SupplierReleaseTrigger::OnRedeemed);

        $this->assertSame(1, $released);
        $this->assertSame(SupplierReleaseTrigger::OnRedeemed, $this->saleRow($order)->release_trigger_at_time);
    }

    public function test_a_payout_needs_a_bank_account_on_the_server_not_only_on_the_screen(): void
    {
        $this->supplier->update(['release_trigger' => SupplierReleaseTrigger::OnPayment->value, 'payout_bank_account_number' => null]);
        $this->sale();

        $this->actingAs($this->superAdmin())
            ->postJson('/api/v1/supplier-payouts', ['supplier_id' => $this->supplier->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier');
    }

    public function test_every_payout_step_is_audited_and_cancel_is_super_admin_only(): void
    {
        $this->supplier->update(['release_trigger' => SupplierReleaseTrigger::OnPayment->value]);
        $this->sale();
        $owner = $this->superAdmin();

        $id = $this->actingAs($owner)->postJson('/api/v1/supplier-payouts', ['supplier_id' => $this->supplier->id])
            ->assertCreated()->json('data.id');

        $admin = User::factory()->companyAdmin()->create(['company_id' => $this->seller->id]);
        $this->actingAs($admin)->postJson("/api/v1/supplier-payouts/{$id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/supplier-payouts/{$id}/cancel", [])->assertStatus(422);
        $this->actingAs($owner)->postJson("/api/v1/supplier-payouts/{$id}/cancel", ['reason' => 'บัญชีผิด'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'บัญชีผิด');

        $id2 = $this->actingAs($owner)->postJson('/api/v1/supplier-payouts', ['supplier_id' => $this->supplier->id])->json('data.id');
        $this->actingAs($owner)->postJson("/api/v1/supplier-payouts/{$id2}/mark-transferred", ['transfer_reference' => 'T-1'])->assertOk();

        $this->assertSame(
            ['supplier_payout.opened', 'supplier_payout.cancelled', 'supplier_payout.opened', 'supplier_payout.transferred'],
            AuditLog::where('auditable_type', SupplierWithdrawalRequest::class)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_a_live_deal_cannot_be_left_without_the_terms_payments_need(): void
    {
        $owner = $this->superAdmin();
        Product::factory()->create(['company_id' => null, 'supplier_id' => $this->supplier->id, 'is_active' => true]);

        $this->actingAs($owner)->putJson("/api/v1/suppliers/{$this->supplier->id}", ['release_trigger' => null])
            ->assertStatus(422)->assertJsonValidationErrors('release_trigger');
        $this->actingAs($owner)->putJson("/api/v1/suppliers/{$this->supplier->id}", ['gp_mode' => null, 'gp_value' => null])
            ->assertStatus(422)->assertJsonValidationErrors('gp_value');

        $this->assertNotNull($this->supplier->fresh()->release_trigger);
    }

    public function test_clearing_terms_is_fine_once_nothing_is_on_sale_and_changes_are_audited(): void
    {
        $owner = $this->superAdmin();
        Product::factory()->create(['company_id' => null, 'supplier_id' => $this->supplier->id, 'is_active' => false]);

        $this->actingAs($owner)->putJson("/api/v1/suppliers/{$this->supplier->id}", ['gp_mode' => SupplierGpMode::PercentOfSale->value, 'gp_value' => 2500, 'name' => 'ชื่อใหม่'])->assertOk();
        $this->actingAs($owner)->putJson("/api/v1/suppliers/{$this->supplier->id}", ['release_trigger' => null])->assertOk();

        $logs = AuditLog::where('action', 'supplier.updated')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['gp_value' => 3000], $logs[0]->old_values);
        $this->assertSame(['gp_value' => 2500], $logs[0]->new_values, 'the name is not money');
        $this->assertSame('on_delivered', $logs[1]->old_values['release_trigger']);
    }

    public function test_a_product_with_its_own_gp_still_needs_the_suppliers_trigger_to_go_on_sale(): void
    {
        $this->supplier->update(['release_trigger' => null]);

        $this->actingAs($this->superAdmin())->postJson('/api/v1/products', [
            'is_platform' => true,
            'brand_id' => Brand::factory()->create(['company_id' => null])->id,
            'category_id' => ProductCategory::factory()->create(['company_id' => null])->id,
            'name' => 'สินค้าคู่ค้า',
            'price_satang' => 100000,
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
            'is_active' => true,
            'supplier_id' => $this->supplier->id,
            'supplier_gp_mode' => SupplierGpMode::PercentOfSale->value,
            'supplier_gp_value' => 2000,
        ])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
    }

    public function test_the_suppliers_own_statement_shows_order_numbers_and_refunds(): void
    {
        $this->supplier->update(['release_trigger' => SupplierReleaseTrigger::OnPayment->value]);
        $order = $this->sale();
        $this->refund($order);

        $rows = $this->actingAs($this->partner())->getJson('/api/v1/supplier/settlements')->assertOk()->json('data');

        $this->assertSame([$order->order_number, $order->order_number], array_column($rows, 'order_number'));
        $this->assertEqualsCanonicalizing(['sale', 'refund'], array_column($rows, 'entry_kind'));
    }

    public function test_a_partner_cannot_confirm_receipt_on_their_own_shipment(): void
    {
        $order = $this->sale();
        $this->ship($order);
        $partner = $this->partner();
        $this->assertSame(UserRole::CompanyPartner, $partner->role);

        $this->assertContains($this->actingAs($partner)->postJson("/api/v1/orders/{$order->id}/receipt")->status(), [403, 404]);
        $this->assertNull($order->fresh()->received_at);
    }
}
