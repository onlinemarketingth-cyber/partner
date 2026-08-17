<?php

namespace Tests\Feature\Order;

use App\Console\Commands\BackfillLegacyPaidOrderVouchersCommand;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Mail\OrderPaymentConfirmedMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderVoucher;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Services\Order\OrderVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// TASK-193 — `vouchers:backfill-legacy-paid-orders`. Covers: backfilling
// every Paid-but-voucherless order via the real OrderVoucherService::issueFor()
// (never a second implementation), leaving an order that already has a
// voucher untouched, leaving an unpaid order untouched, --dry-run writing
// nothing, idempotency on re-run, NEVER touching
// NotificationType::OrderPaymentConfirmed or OrderPaymentConfirmedMail, and
// one order's failure not blocking/rolling-back the rest of the batch.
class BackfillLegacyPaidOrderVouchersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Paid (or, if $status given, other-status) order for a fresh
     * company/agent/client/product, with NO voucher unless the caller mints
     * one afterwards. $paidAt lets a test prove the backfilled expiry is
     * anchored to the ORDER's own historical paid_at, not to "today".
     */
    private function makeOrder(
        Company $company,
        array $productAttrs = [],
        OrderStatus $status = OrderStatus::Paid,
        ?\DateTimeInterface $paidAt = null,
    ): Order {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $agent->id,
        ]);
        $product = Product::factory()->create(array_merge(
            ['company_id' => $company->id],
            $productAttrs,
        ));
        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
        ]);

        return Order::factory()->create([
            'referral_id' => $referral->id,
            'status' => $status,
            'paid_at' => $status === OrderStatus::Paid ? ($paidAt ?? now()->subDays(30)) : null,
        ]);
    }

    // -----------------------------------------------------------------
    // Happy path — backfill 3, skip 1 already-vouchered, leave 1 unpaid alone
    // -----------------------------------------------------------------

    public function test_it_backfills_every_paid_voucherless_order_and_leaves_the_others_untouched(): void
    {
        $company = Company::factory()->create();

        $orderA = $this->makeOrder($company, ['voucher_usage_quota' => 3, 'voucher_validity_days' => 7], OrderStatus::Paid, now()->subDays(30));
        $orderB = $this->makeOrder($company, ['voucher_usage_quota' => null, 'voucher_validity_days' => null], OrderStatus::Paid, now()->subDays(10));
        $orderC = $this->makeOrder($company, ['voucher_usage_quota' => 1, 'voucher_validity_days' => 14], OrderStatus::Paid, now()->subDays(5));

        // Already has a voucher — must be left exactly as-is (not replaced).
        $orderAlreadyVouchered = $this->makeOrder($company, [], OrderStatus::Paid, now()->subDays(2));
        $existingVoucher = OrderVoucher::create([
            'order_id' => $orderAlreadyVouchered->id,
            'code' => str_repeat('z', 40),
            'usage_quota' => 9,
            'used_count' => 2,
            'expires_at' => null,
        ]);

        // Unpaid — must never get a voucher.
        $unpaidOrder = $this->makeOrder($company, [], OrderStatus::Pending);

        $this->artisan(BackfillLegacyPaidOrderVouchersCommand::class)->assertSuccessful();

        $this->assertSame(1, OrderVoucher::where('order_id', $orderA->id)->count());
        $voucherA = OrderVoucher::where('order_id', $orderA->id)->firstOrFail();
        $this->assertSame(3, $voucherA->usage_quota);
        $this->assertSame(0, $voucherA->used_count);
        $this->assertNotNull($voucherA->expires_at);
        // Anchored to the ORDER's own historical paid_at (30 days ago + 7),
        // not to today — proves issueFor() read $order->paid_at, not now().
        $this->assertTrue($voucherA->expires_at->isSameDay($orderA->paid_at->clone()->addDays(7)));

        $voucherB = OrderVoucher::where('order_id', $orderB->id)->firstOrFail();
        $this->assertNull($voucherB->usage_quota);
        $this->assertNull($voucherB->expires_at);

        $voucherC = OrderVoucher::where('order_id', $orderC->id)->firstOrFail();
        $this->assertSame(1, $voucherC->usage_quota);
        $this->assertTrue($voucherC->expires_at->isSameDay($orderC->paid_at->clone()->addDays(14)));

        // Untouched: still exactly one voucher, same code, same counters.
        $this->assertSame(1, OrderVoucher::where('order_id', $orderAlreadyVouchered->id)->count());
        $stillThere = OrderVoucher::where('order_id', $orderAlreadyVouchered->id)->firstOrFail();
        $this->assertSame($existingVoucher->code, $stillThere->code);
        $this->assertSame(2, $stillThere->used_count);

        // Unpaid order still has none.
        $this->assertSame(0, OrderVoucher::where('order_id', $unpaidOrder->id)->count());
    }

    // -----------------------------------------------------------------
    // --dry-run writes nothing
    // -----------------------------------------------------------------

    public function test_dry_run_reports_the_count_but_creates_no_vouchers(): void
    {
        $company = Company::factory()->create();

        $this->makeOrder($company);
        $this->makeOrder($company);
        $this->makeOrder($company);
        // Already vouchered — must not be counted as "would backfill".
        $vouchered = $this->makeOrder($company);
        OrderVoucher::create([
            'order_id' => $vouchered->id,
            'code' => str_repeat('y', 40),
            'usage_quota' => null,
            'used_count' => 0,
            'expires_at' => null,
        ]);

        $this->artisan(BackfillLegacyPaidOrderVouchersCommand::class, ['--dry-run' => true])
            ->expectsOutputToContain('จะสร้าง voucher ให้ 3 รายการ')
            ->assertSuccessful();

        // Only the one pre-seeded voucher exists — dry-run wrote nothing.
        $this->assertSame(1, OrderVoucher::count());
    }

    // -----------------------------------------------------------------
    // Must never touch the payment-confirmation notification/email path
    // -----------------------------------------------------------------

    public function test_it_never_fires_the_order_payment_confirmed_notification_or_mail(): void
    {
        Mail::fake();
        $company = Company::factory()->create();

        $this->makeOrder($company);
        $this->makeOrder($company);

        $beforeCount = Notification::where('type', NotificationType::OrderPaymentConfirmed)->count();

        $this->artisan(BackfillLegacyPaidOrderVouchersCommand::class)->assertSuccessful();

        $afterCount = Notification::where('type', NotificationType::OrderPaymentConfirmed)->count();

        $this->assertSame($beforeCount, $afterCount);
        $this->assertSame(0, $beforeCount); // sanity: nothing ever created one in this test
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    // -----------------------------------------------------------------
    // Idempotent — a clean second run backfills nothing
    // -----------------------------------------------------------------

    public function test_re_running_after_a_successful_run_is_a_no_op(): void
    {
        $company = Company::factory()->create();
        $this->makeOrder($company);
        $this->makeOrder($company);

        $this->artisan(BackfillLegacyPaidOrderVouchersCommand::class)->assertSuccessful();
        $this->assertSame(2, OrderVoucher::count());

        // Second run: the "Paid AND no voucher" query now returns nothing.
        $this->artisan(BackfillLegacyPaidOrderVouchersCommand::class)
            ->expectsOutputToContain('ไม่ต้องทำอะไร')
            ->assertSuccessful();

        $this->assertSame(2, OrderVoucher::count());
    }

    // -----------------------------------------------------------------
    // One order's failure must not block or roll back the others
    // -----------------------------------------------------------------

    public function test_a_single_order_failing_mid_issuefor_does_not_block_or_roll_back_the_rest(): void
    {
        $company = Company::factory()->create();

        $goodOrder1 = $this->makeOrder($company);
        $failingOrder = $this->makeOrder($company);
        $goodOrder2 = $this->makeOrder($company);

        // Force issueFor() to throw for exactly ONE order, while every other
        // call falls through to the REAL implementation — proves the
        // command still routes through OrderVoucherService::issueFor() and
        // is not a second, divergent implementation.
        $this->partialMock(OrderVoucherService::class, function ($mock) use ($failingOrder) {
            $mock->shouldReceive('issueFor')
                ->withArgs(fn (Order $order) => $order->id === $failingOrder->id)
                ->once()
                ->andThrow(new \RuntimeException('Simulated failure for test'));
        });

        $this->artisan(BackfillLegacyPaidOrderVouchersCommand::class)
            ->assertFailed();

        $this->assertSame(1, OrderVoucher::where('order_id', $goodOrder1->id)->count());
        $this->assertSame(1, OrderVoucher::where('order_id', $goodOrder2->id)->count());
        $this->assertSame(0, OrderVoucher::where('order_id', $failingOrder->id)->count());
    }

    // -----------------------------------------------------------------
    // Sanity: never imports/uses the notification mailable class directly —
    // a compile-time guard is impossible from a test, so this at least
    // proves the class itself is untouched by asserting it was never queued.
    // -----------------------------------------------------------------

    public function test_no_order_payment_confirmed_mailable_is_ever_queued(): void
    {
        Mail::fake();
        $company = Company::factory()->create();
        $this->makeOrder($company);

        $this->artisan(BackfillLegacyPaidOrderVouchersCommand::class)->assertSuccessful();

        Mail::assertNotSent(OrderPaymentConfirmedMail::class);
    }
}
