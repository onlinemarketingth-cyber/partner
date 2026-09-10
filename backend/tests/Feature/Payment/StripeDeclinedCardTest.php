<?php

namespace Tests\Feature\Payment;

use App\Enums\CommissionRateType;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\CompanyPaymentGatewaySetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 2026-09-10 (human, testing with Stripe's 4000000000000002: "ผมทดสอบ stripe
 * แบบ card_declined ให้ผิด แต่หน้า frontend ยังขึ้นให้บัตรอยู่ และขึ้นชำระเงินแล้ว
 * เช็คตัวแปรรับค่าจาก stripe หน่อย").
 *
 * Two separate questions, and this file answers both from the wire.
 *
 * FIRST: can a refused card ever mark an order paid? Every value the decision
 * turns on comes out of Stripe's payload, and the one that matters is
 * `payment_status` — NOT the event name. `checkout.session.completed` means
 * the customer finished the page, which a declined card can also do. Below,
 * the same event that marks an order paid when `payment_status` says `paid`
 * leaves it untouched when it does not.
 *
 * SECOND: what does the customer see afterwards? Until today, nothing at all.
 * The refusal was written onto the order and the agent was told; the pay page
 * still showed the card button and no explanation, so the obvious next move
 * was to try the same card again.
 */
class StripeDeclinedCardTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    // ── What the payload actually decides ────────────────────────────

    public function test_a_declined_card_never_marks_the_order_paid(): void
    {
        /*
         * The exact shape Stripe sends when a session finishes without money:
         * the SAME event type as a successful one, differing only in
         * `payment_status`. Reading the event name alone — which is the
         * tempting shortcut — would mark this order paid.
         */
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_declined',
                'payment_intent' => 'pi_test_declined',
                'payment_status' => 'no_payment_required_but_not_really',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ])->assertOk();

        $order->refresh();

        $this->assertSame(OrderStatus::AwaitingVerification, $order->status);
        $this->assertNull($order->gateway_charge_id);
        $this->assertNull($order->voucher);
        $this->assertNotNull($order->last_payment_error);
    }

    public function test_a_pending_promptpay_session_is_not_a_failure_either(): void
    {
        // `unpaid` on a completed session is money still in flight, not a
        // refusal. Recording it as a failure would tell a customer their
        // payment was declined while it was still on its way.
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_pending',
                'payment_status' => 'unpaid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ])->assertOk();

        $order->refresh();

        $this->assertNull($order->last_payment_error);
        $this->assertNull($order->gateway_charge_id);
    }

    public function test_the_same_event_does_mark_it_paid_when_stripe_says_paid(): void
    {
        // The control. Without this, the test above would also pass if the
        // webhook were broken and nothing ever marked anything paid.
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_ok',
                'payment_intent' => 'pi_test_ok',
                'payment_status' => 'paid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ])->assertOk();

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('pi_test_ok', $order->gateway_charge_id);
    }

    public function test_a_forged_event_is_refused_before_anything_is_read(): void
    {
        // Worth pinning next to the others: the reason "which values do we
        // trust from Stripe" is answerable at all is that an unsigned body
        // never reaches the code that reads them.
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_forged',
                'payment_intent' => 'pi_forged',
                'payment_status' => 'paid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ], signature: 'v1=deadbeef')->assertStatus(401);

        $this->assertNull($order->fresh()->gateway_charge_id);
    }

    // ── What the customer is told ────────────────────────────────────

    public function test_the_pay_page_tells_the_customer_their_card_was_refused(): void
    {
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_declined',
                'payment_intent' => 'pi_test_declined',
                'payment_status' => 'card_declined',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ])->assertOk();

        $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('data.gateway.payment_received', false)
            // The one that was missing: the refusal reached the agent and the
            // order row, and never the person who had to act on it.
            // The WORDING, not the raw `payment_status` value. This string is
            // read by a customer on the pay page; "card_declined" is a value.
            ->assertJsonPath('data.gateway.last_error', 'บัตรถูกปฏิเสธโดยธนาคารผู้ออกบัตร — กรุณาลองบัตรใบอื่น หรือติดต่อธนาคารของคุณ');
    }

    public function test_an_untouched_order_says_nothing_about_failures(): void
    {
        [, $order] = $this->stripeOrder();

        $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('data.gateway.last_error', null);
    }

    // ── The events a refused card ACTUALLY sends ─────────────────────

    public function test_the_event_stripe_really_sends_for_a_declined_card_is_recorded(): void
    {
        /*
         * THE BUG THIS FILE WAS REOPENED FOR (human, testing with
         * 4000000000000002): "ระบบ stripe คือค่ามาไม่สำเร็จ ของเรายังจ่ายสำเร็จ
         * อยู่เลย".
         *
         * A card refused INSIDE Checkout never completes the session, so
         * `checkout.session.completed` — the only card-failure route the
         * translation knew — is never sent at all. Stripe sends
         * `payment_intent.payment_failed`, which fell through to Ignore: the
         * order was untouched, the agent was never told, and the customer's
         * page looked exactly as it had before they tried.
         *
         * The payload below is the real shape, including the metadata copy
         * that `payment_intent_data[metadata]` puts on the intent precisely so
         * this event can be tied back to an order.
         */
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_test_declined',
                'object' => 'payment_intent',
                'amount' => 890000,
                'status' => 'requires_payment_method',
                'metadata' => ['order_token' => $order->public_token],
                'last_payment_error' => [
                    'type' => 'card_error',
                    'code' => 'card_declined',
                    'decline_code' => 'generic_decline',
                    'message' => 'Your card was declined.',
                ],
            ]],
        ])->assertOk();

        $order->refresh();

        $this->assertNotNull($order->last_payment_error);
        $this->assertNotNull($order->last_payment_error_at);
        // Untouched in every way that matters: not paid, and the charge id is
        // still free for the attempt that succeeds.
        $this->assertSame(OrderStatus::AwaitingVerification, $order->status);
        $this->assertNull($order->gateway_charge_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.gateway_payment_failed',
            'auditable_id' => $order->id,
        ]);
    }

    public function test_a_charge_failed_event_is_recorded_too(): void
    {
        // The other half of the same decline. Its reason lives somewhere else
        // in the payload — `outcome.reason` rather than
        // `last_payment_error.decline_code` — which is the whole reason the
        // mapping reads several fields.
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'charge.failed',
            'data' => ['object' => [
                'id' => 'ch_test_declined',
                'payment_intent' => 'pi_test_declined',
                'amount' => 890000,
                'failure_code' => 'card_declined',
                'failure_message' => 'Your card was declined.',
                'outcome' => ['reason' => 'insufficient_funds', 'network_status' => 'declined_by_network'],
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ])->assertOk();

        $this->assertStringContainsString('ยอดเงินในบัตรไม่พอ', (string) $order->fresh()->last_payment_error);
    }

    public function test_the_reason_is_said_in_words_the_customer_can_act_on(): void
    {
        // "insufficient_funds" is actionable — use another card. Passing
        // Stripe's own English string through would put a US-shaped sentence
        // in front of a Thai customer.
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_test_funds',
                'metadata' => ['order_token' => $order->public_token],
                'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'expired_card'],
            ]],
        ])->assertOk();

        $this->assertStringContainsString('บัตรหมดอายุ', (string) $order->fresh()->last_payment_error);
    }

    public function test_a_lost_or_stolen_card_is_never_announced_to_whoever_is_holding_it(): void
    {
        /*
         * Stripe's own guidance, and it is a real safety rule rather than a
         * style choice: if the person at the checkout is the thief, telling
         * them the issuer reported the card stolen is a warning; if they are
         * not, it is a shop accusing a customer of theft. The issuer contacts
         * the real cardholder. So it gets the ordinary decline wording.
         */
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_test_stolen',
                'metadata' => ['order_token' => $order->public_token],
                'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'stolen_card'],
            ]],
        ])->assertOk();

        $message = (string) $order->fresh()->last_payment_error;

        $this->assertStringNotContainsString('ขโมย', $message);
        $this->assertStringNotContainsString('stolen', $message);
        $this->assertStringContainsString('บัตรถูกปฏิเสธ', $message);
    }

    public function test_a_failed_attempt_never_blocks_the_one_that_succeeds(): void
    {
        /*
         * The expensive way to get this wrong: record the failure by claiming
         * `gateway_charge_id`, and the customer's second card — the one that
         * works — finds the id already taken and is refused as a duplicate.
         * Money in, order unpaid, and no way to fix it from any screen.
         */
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_first_try',
                'metadata' => ['order_token' => $order->public_token],
                'last_payment_error' => ['decline_code' => 'generic_decline'],
            ]],
        ])->assertOk();

        $this->postStripeWebhook($company, [
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_second_try',
                'payment_intent' => 'pi_second_try',
                'payment_status' => 'paid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ])->assertOk();

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('pi_second_try', $order->gateway_charge_id);
    }

    // ── The address, on the card path ────────────────────────────────

    public function test_a_card_payer_is_asked_where_to_send_a_physical_product(): void
    {
        /*
         * THE GAP THIS CLOSES. ADR-033 §2.5/D1 collected the address in the
         * SAME request as the slip — right while the slip was the only way to
         * pay, and wrong the moment the card button appeared beside it: a
         * customer buying a physical product with a card went straight to the
         * gateway and was never asked. The order came back paid, with nowhere
         * to send the goods, and nothing said one was missing.
         */
        [, $order] = $this->stripeOrder(requiresShipping: true);

        $this->postJson("/api/v1/pay/{$order->public_token}/intent", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shipping_recipient_name', 'shipping_phone', 'shipping_address']);
    }

    public function test_the_address_is_saved_before_the_gateway_is_opened(): void
    {
        /*
         * Saved FIRST, so it survives a customer who opens the gateway and
         * then abandons it: an address with no payment is recoverable, a
         * payment with no address is a phone call.
         *
         * The gateway call itself fails here (no HTTP fake), which is exactly
         * what makes the point — the address is on the row regardless.
         */
        [, $order] = $this->stripeOrder(requiresShipping: true);

        $this->postJson("/api/v1/pay/{$order->public_token}/intent", [
            'shipping_recipient_name' => 'สมชาย ใจดี',
            'shipping_phone' => '0812345678',
            'shipping_address' => '123 ถนนสีลม กรุงเทพฯ 10500',
        ]);

        $order->refresh();

        $this->assertSame('สมชาย ใจดี', $order->shipping_recipient_name);
        $this->assertSame('0812345678', $order->shipping_phone);
        $this->assertSame('123 ถนนสีลม กรุงเทพฯ 10500', $order->shipping_address);
    }

    public function test_a_non_physical_product_is_never_blocked_on_an_address(): void
    {
        // Three fields nobody will ever read must not stand between a
        // customer and a service they are trying to buy.
        [, $order] = $this->stripeOrder();

        $this->postJson("/api/v1/pay/{$order->public_token}/intent", [])
            ->assertJsonMissingValidationErrors(['shipping_recipient_name']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /** @param array<string, mixed> $event */
    private function postStripeWebhook(Company $company, array $event, ?string $signature = null): TestResponse
    {
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        // The real header shape: `t=<unix>,v1=<hmac of "t.body">`. Built here
        // rather than stubbed so the verification path is genuinely exercised.
        $header = $signature ?? 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        return $this->call(
            'POST',
            "/api/v1/webhooks/payments/stripe/{$company->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},{$header}",
            ],
            $body,
        );
    }

    /**
     * A company with Stripe switched on, and an order sitting at
     * awaiting-verification with a card attempt in flight.
     *
     * @return array{0: Company, 1: Order}
     */
    private function stripeOrder(bool $requiresShipping = false): array
    {
        $company = Company::factory()->create();

        // Same fixture shape as GatewayChargeAndWebhookTest's Omise company:
        // the credentials row, plus the company pointing at that provider —
        // both, because activeConfig() needs the second and the webhook needs
        // the first.
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
            'price_satang' => 890000,
            'requires_shipping' => $requiresShipping,
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
}
