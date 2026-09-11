<?php

namespace Tests\Feature\Order;

use App\Enums\CommissionRateType;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PipelineStage;
use App\Mail\OrderPaymentConfirmedMail;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\CompanyPaymentGatewaySetting;
use App\Models\Order;
use App\Models\PlatformMailSetting;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Platform\PlatformMailSettingService;
use App\Support\VoucherCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * 2026-09-11 (human: "2. ถ้าขึ้นสถานะสำเร็จ ต้องส่ง email ให้ลูกค้า — ยังไม่ส่ง").
 *
 * ── THE BUG ──
 *
 * The confirmation email was sent from OrderController::confirm(). An order
 * paid BY CARD never goes through that controller — the gateway's webhook
 * confirms it — so the customers who used the payment page, which is all of
 * them, were the ones who never heard anything. The agent got their in-app
 * notification, the voucher was minted, and the person who had just paid was
 * left with a code on a tab they had closed.
 *
 * The email now lives in CustomerPaymentConfirmationMailer, which every
 * confirmation route calls. These tests are written against the WEBHOOK — the
 * route that was broken — rather than against the Mailer in isolation,
 * because a unit test of the Mailer would have passed the whole time the bug
 * existed: nothing was wrong with the sending, only with who called it.
 */
class GatewayConfirmationEmailTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    public function test_a_card_payment_confirmed_by_the_webhook_emails_the_customer(): void
    {
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->stripeOrder();

        $this->postPaidWebhook($company, $order, 'pi_paid_1')->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) use ($order) {
            return $mail->hasTo('customer@example.com')
                // And it carries the thing the customer actually bought: the
                // voucher minted by the very confirmation that triggered it.
                // The mailer reloads the relation for this reason — an order
                // whose `voucher` was read before the mint would send an
                // announcement with no code in it.
                && str_contains($mail->render(), VoucherCode::format($order->voucher->code));
        });
    }

    public function test_the_link_in_that_email_opens_without_signing_in(): void
    {
        /*
         * 2026-09-11 (human: "ส่ง email ให้ลูกค้าต้องไม่ติด Login สามารถดูได้
         * เหมือนหน้าชำระสำเร็จ").
         *
         * The customer has no account and never will — nobody registers with
         * an insurance portal to collect a voucher they have paid for. So the
         * receipt has to be reachable by the link alone. `/pay/{token}` is
         * built that way (ADR-011: the token IS the credential), and this
         * test is what keeps it that way: an `auth:sanctum` added to the
         * public payment group during a tidy-up would break every
         * confirmation email ever sent, silently, and only for people who
         * cannot report it.
         *
         * Asserted end to end — the address the email actually carries, then
         * the request that address's page makes — rather than by trusting the
         * route file to still say what it says today.
         */
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->stripeOrder();

        $this->postPaidWebhook($company, $order, 'pi_public_link')->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) use ($order) {
            return str_contains($mail->render(), '/pay/'.$order->public_token);
        });

        // Logged out — no actingAs anywhere in this test — and the page's own
        // request answers in full, voucher included.
        $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.voucher.code', $order->fresh()->voucher->code);
    }

    public function test_the_admin_button_still_sends_it_too(): void
    {
        /*
         * The other half of the same rule, pinned here next to it: moving the
         * send out of the controller must not have taken it away from the
         * path that already worked. One method, both routes, no drift — that
         * is the entire point of the refactor and it is worth one test.
         */
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->stripeOrder();

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class);
    }

    public function test_a_declined_card_tells_the_customer_nothing_of_the_sort(): void
    {
        // The failure that matters most in this file: an email saying a
        // payment succeeded, sent about one that did not.
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_declined',
                'metadata' => ['order_token' => $order->public_token],
                'last_payment_error' => ['decline_code' => 'insufficient_funds'],
            ]],
        ])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_a_retried_webhook_does_not_send_a_second_confirmation(): void
    {
        /*
         * Every gateway re-sends events, routinely and by design. The charge
         * id is claimed once (GUARD 2), so the second delivery returns before
         * it reaches the confirmation — and therefore before it reaches the
         * email. Two identical "payment received" emails for one payment is
         * how a customer starts wondering whether they were charged twice.
         */
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->stripeOrder();

        $this->postPaidWebhook($company, $order, 'pi_paid_2')->assertOk();
        $this->postPaidWebhook($company, $order, 'pi_paid_2')->assertOk();

        Mail::assertSentCount(1);
    }

    public function test_an_order_with_no_customer_email_is_still_confirmed(): void
    {
        // An agent keying an order in by hand is not required to have an
        // address for the customer. The sale must close regardless — the
        // agent's own in-app notification is the delivery that always exists.
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->stripeOrder(clientEmail: null);

        $this->postPaidWebhook($company, $order, 'pi_paid_3')->assertOk();

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_while_platform_mail_is_switched_off(): void
    {
        /*
         * With mail off the runtime mailer is still the `log` driver
         * (MailSettingsService::applyRuntimeConfig fails closed), so "sending"
         * would write a customer's confirmation into a log file and report
         * success. Skipping is the honest version of the same outcome.
         */
        Mail::fake();
        // Deliberately no enablePlatformMail() here.

        [$company, $order] = $this->stripeOrder();

        $this->postPaidWebhook($company, $order, 'pi_paid_4')->assertOk();

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_an_smtp_failure_never_un_confirms_a_real_payment(): void
    {
        /*
         * THE ONE THAT PROTECTS THE MONEY.
         *
         * The customer has been charged. If a dead SMTP host threw out of the
         * webhook, the endpoint would answer non-2xx, Stripe would retry the
         * same event for hours, and every retry would fail the same way — all
         * because of an email. The payment is the record; the email is a
         * courtesy, and a courtesy may not overturn a record.
         */
        $this->enablePlatformMail();

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new RuntimeException('Connection to smtp.example.test:587 timed out'));

        [$company, $order] = $this->stripeOrder();

        $this->postPaidWebhook($company, $order, 'pi_paid_5')->assertOk();

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('pi_paid_5', $order->gateway_charge_id);
        $this->assertNotNull($order->voucher);
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function postPaidWebhook(Company $company, Order $order, string $paymentIntent): TestResponse
    {
        return $this->postStripeWebhook($company, [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_'.$paymentIntent,
                'payment_intent' => $paymentIntent,
                // `payment_status`, not the event name, is what makes it paid
                // (StripeDeclinedCardTest pins that on its own).
                'payment_status' => 'paid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ]);
    }

    /** @param array<string, mixed> $event */
    private function postStripeWebhook(Company $company, array $event): TestResponse
    {
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        return $this->call(
            'POST',
            "/api/v1/webhooks/payments/stripe/{$company->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
            ],
            $body,
        );
    }

    /**
     * A company with Stripe switched on and an order one confirmation away
     * from paid. Same shape as StripeDeclinedCardTest::stripeOrder(), with
     * the customer's email under the test's control.
     *
     * @return array{0: Company, 1: Order}
     */
    private function stripeOrder(?string $clientEmail = 'customer@example.com'): array
    {
        $company = Company::factory()->create();

        CompanyPaymentGatewaySetting::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'provider' => PaymentProvider::Stripe->value,
            'credentials' => [
                'secret_key' => 'sk_test_x',
                'publishable_key' => 'pk_test_x',
                'webhook_secret' => self::SECRET,
            ],
            'is_live' => false,
            'verified_at' => now(),
            'verified_note' => 'test fixture',
        ]);

        $company->forceFill(['payment_provider' => PaymentProvider::Stripe->value])->save();
        $company->refresh();

        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        $product = Product::factory()->create([
            'company_id' => $company->id,
            'name' => 'ตรวจสุขภาพประจำปี',
            'price_satang' => 890000,
            'voucher_usage_quota' => 3,
            'voucher_validity_days' => 30,
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
        ]);

        $referral = Referral::create([
            'company_id' => $company->id,
            'client_id' => Client::factory()->create([
                'company_id' => $company->id,
                'referring_agent_id' => $agent->id,
                'email' => $clientEmail,
            ])->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::Finish1stDoctorMeeting,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);

        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);
        $order->forceFill([
            'payment_provider' => PaymentProvider::Stripe->value,
            'gateway_mode' => 'test',
        ])->save();

        return [$company, $order->fresh()];
    }

    /** Platform mail on, and the shared cache key told about it. */
    private function enablePlatformMail(): void
    {
        PlatformMailSetting::query()->create([
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'encryption' => 'tls',
            'username' => 'noreply@example.test',
            'password' => 'whatever',
            'from_address' => 'noreply@example.test',
            'from_name' => 'Test Sender',
            'is_enabled' => true,
        ]);
        Cache::forget(PlatformMailSettingService::CACHE_KEY);
    }
}
