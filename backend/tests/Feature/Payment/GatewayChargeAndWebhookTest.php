<?php

namespace Tests\Feature\Payment;

use App\Enums\CommissionRateType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PipelineStage;
use App\Http\Requests\Order\ChargeOrderRequest;
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
use App\Services\Order\OrderService;
use App\Services\Payment\CompanyPaymentGatewayService;
use App\Services\Payment\GatewayPaymentService;
use App\Services\Payment\Gateways\WebhookOutcome;
use App\Services\Payment\Gateways\WebhookResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Money arriving from a gateway, and the ways that can go wrong.
 *
 * ── WHAT IS ACTUALLY BEING DEFENDED ──
 *
 * Confirming an order writes a BR-4 commission ledger row. Those rows are
 * IMMUTABLE — that is the whole point of BR-4 — so anything that causes one
 * to be written wrongly cannot be corrected afterwards, only compensated for.
 * Every case in this file is a way to make one appear when it should not:
 *
 * 1. A FORGED WEBHOOK. The endpoint is public and unauthenticated. Without a
 *    signature check, "POST charge.complete" from anyone on the internet is
 *    free goods plus a commission payout.
 *
 * 2. A DUPLICATE WEBHOOK. Not an edge case — every gateway retries, routinely,
 *    and Omise will re-send an event it did not see a 2xx for. Two ledger
 *    rows for one sale is an agent paid twice for money received once.
 *
 * 3. AN AMOUNT THAT DOES NOT MATCH. A ฿1 charge confirming a ฿8,900 order
 *    pays commission on eight thousand baht nobody sent.
 *
 * 4. A DOUBLE CHARGE. The customer's side of the same problem: a double-click
 *    or a browser retry taking their money twice. A refund is a worse outcome
 *    than a refusal, so this is refused BEFORE the gateway is called, not
 *    merely deduplicated afterwards.
 *
 * ── WHY THE NETWORK IS FAKED AND WHAT THAT COSTS ──
 *
 * Http::fake() means these cases prove OUR logic, not Omise's. They cannot
 * tell us that Omise's signature header is named what we think, or that its
 * charge response is shaped as we assume. Only a real test-mode transaction
 * can, and one is scheduled (Phase 5) precisely because this file cannot
 * stand in for it. Nothing here should be read as "the Omise integration
 * works".
 */
class GatewayChargeAndWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_shared_secret';

    // ── Fixtures ─────────────────────────────────────────────────────────

    /**
     * A company with a verified, ACTIVE Omise configuration.
     */
    private function omiseCompany(bool $active = true, bool $isLive = false): Company
    {
        $company = Company::factory()->create();

        CompanyPaymentGatewaySetting::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'provider' => PaymentProvider::Omise->value,
            'credentials' => [
                'public_key' => 'pkey_test_abc',
                'secret_key' => 'skey_test_abc',
                'webhook_secret' => self::SECRET,
            ],
            'is_live' => $isLive,
            'verified_at' => now(),
            'verified_note' => 'test fixture',
        ]);

        if ($active) {
            $company->forceFill(['payment_provider' => PaymentProvider::Omise->value])->save();
        }

        return $company->refresh();
    }

    /**
     * An order one step away from being confirmable, exactly as the real
     * flow produces it: a certified agent, a commission rule that will fire,
     * and a referral parked at the stage before Complete Payment.
     *
     * Deliberately NOT `awaitingVerification()` — a card order never has a
     * slip, and using the slip state here would test a path the card flow
     * does not take.
     */
    private function payableOrder(Company $company, PaymentProvider $provider = PaymentProvider::Omise): Order
    {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);

        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
        ]);

        $referral = Referral::create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::Finish1stDoctorMeeting,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);

        return Order::factory()->create([
            'referral_id' => $referral->id,
            'amount_satang' => 890000,
            'payment_provider' => $provider->value,
            'gateway_mode' => 'test',
        ]);
    }

    /** Omise's successful /charges response. */
    private function fakeChargeSuccess(string $chargeId = 'chrg_test_1', ?int $amount = null): void
    {
        Http::fake(['api.omise.co/charges' => Http::response([
            'id' => $chargeId,
            'status' => 'successful',
            'amount' => $amount ?? 890000,
            'currency' => 'THB',
        ], 200)]);
    }

    /** @param array<string, mixed> $charge */
    private function webhookBody(array $charge, string $key = 'charge.complete'): string
    {
        return json_encode(['key' => $key, 'data' => $charge], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function postWebhook(Company $company, string $body, ?string $signature = null, string $provider = 'omise'): TestResponse
    {
        return $this->call(
            'POST',
            "/api/v1/webhooks/payments/{$provider}/{$company->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_OMISE_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, self::SECRET),
            ],
            $body,
        );
    }

    // ── The charge endpoint ──────────────────────────────────────────────

    public function test_a_successful_charge_confirms_the_order_and_pays_commission_exactly_once(): void
    {
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $this->fakeChargeSuccess();

        $this->postJson("/api/v1/pay/{$order->public_token}/charge", ['payment_token' => 'tokn_test_abc'])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Paid->value)
            ->assertJsonPath('data.gateway.payment_received', true);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('chrg_test_1', $order->gateway_charge_id);
        // BR-4 — one sale, one immutable ledger row.
        $this->assertDatabaseCount('commission_ledger', 1);
    }

    public function test_the_amount_sent_to_omise_is_the_orders_own_satang_with_no_conversion(): void
    {
        // A ×100 error on a payment gateway is the most expensive bug
        // available in this codebase, and the protection against it is that
        // there is NO conversion anywhere. Asserting the absence is the only
        // way to keep it absent.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $this->fakeChargeSuccess();

        $this->postJson("/api/v1/pay/{$order->public_token}/charge", ['payment_token' => 'tokn_test_abc'])
            ->assertOk();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.omise.co/charges'
                && (int) $request['amount'] === 890000
                && $request['currency'] === 'THB';
        });
    }

    public function test_the_charge_carries_the_order_token_so_a_later_webhook_can_find_it(): void
    {
        // Without this the webhook is an amount and a charge id belonging to
        // nobody, and a payment that arrives asynchronously can never be
        // matched to the order it paid for.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $this->fakeChargeSuccess();

        $this->postJson("/api/v1/pay/{$order->public_token}/charge", ['payment_token' => 'tokn_test_abc']);

        Http::assertSent(fn ($request) => ($request['metadata']['order_token'] ?? null) === $order->public_token);
    }

    public function test_a_declined_card_shows_the_providers_own_reason_and_leaves_the_order_alone(): void
    {
        // The decline reason is passed through on purpose: "ยอดเกินวงเงิน" is
        // something only the cardholder can act on, and hiding it behind a
        // generic failure turns a fixable problem into an abandoned sale.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        Http::fake(['api.omise.co/charges' => Http::response(['message' => 'insufficient funds'], 402)]);

        $this->postJson("/api/v1/pay/{$order->public_token}/charge", ['payment_token' => 'tokn_test_abc'])
            ->assertStatus(422)
            ->assertJsonPath('errors.payment_token.0', 'insufficient funds');

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->gateway_charge_id);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_a_charge_that_omise_reports_as_unsuccessful_does_not_mark_the_order_paid(): void
    {
        // HTTP 200 with status 'failed'. The event means "we finished
        // processing", not "it worked" — treating the 200 as success would
        // mark declined cards as paid.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        Http::fake(['api.omise.co/charges' => Http::response([
            'id' => 'chrg_test_dead',
            'status' => 'failed',
            'amount' => 890000,
            'failure_message' => 'stolen_or_lost_card',
        ], 200)]);

        $this->postJson("/api/v1/pay/{$order->public_token}/charge", ['payment_token' => 'tokn_test_abc'])
            ->assertStatus(422)
            ->assertJsonPath('errors.payment_token.0', 'stolen_or_lost_card');

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_an_order_already_charged_is_refused_before_the_gateway_is_called(): void
    {
        // The customer's protection, not the ledger's. The UNIQUE charge id
        // stops a second charge being RECORDED; it does not stop a card
        // being charged twice, and a refund is worse than a refusal.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $order->forceFill(['gateway_charge_id' => 'chrg_already'])->save();
        Http::fake();

        $this->postJson("/api/v1/pay/{$order->public_token}/charge", ['payment_token' => 'tokn_test_abc'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_an_order_stamped_for_another_provider_cannot_be_charged_here(): void
    {
        // The order carries the provider it was CREATED for, because its /pay
        // link is already in a customer's hand with instructions they read.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company, PaymentProvider::Manual);
        Http::fake();

        $this->postJson("/api/v1/pay/{$order->public_token}/charge", ['payment_token' => 'tokn_test_abc'])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertNull($order->refresh()->gateway_charge_id);
    }

    public function test_a_company_that_switched_gateways_away_cannot_be_charged_on_an_old_link(): void
    {
        // Fails CLOSED. A company that switched has decided to stop
        // collecting money that way; honouring the old link past that
        // decision would take money through a route the company closed.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $company->forceFill(['payment_provider' => PaymentProvider::Manual->value])->save();
        Http::fake();

        $this->postJson("/api/v1/pay/{$order->public_token}/charge", ['payment_token' => 'tokn_test_abc'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_the_charge_endpoint_never_accepts_a_card_number(): void
    {
        // Card data is ABSENT from this request, not validated-and-discarded.
        // If a field were ever added it would have to appear in rules(), and
        // this asserts nothing there resembles one.
        $rules = (new ChargeOrderRequest)->rules();

        $this->assertSame(['payment_token'], array_keys($rules));
    }

    // ── The pay page's own view of all this ──────────────────────────────

    public function test_the_pay_page_gets_the_public_key_and_never_the_secret(): void
    {
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);

        $response = $this->getJson("/api/v1/pay/{$order->public_token}");

        $response->assertOk()
            ->assertJsonPath('data.gateway.provider', 'omise')
            ->assertJsonPath('data.gateway.intent.kind', 'tokenize')
            ->assertJsonPath('data.gateway.intent.public_key', 'pkey_test_abc');

        // Not "masked" — absent from the entire payload, in any form.
        $this->assertStringNotContainsString('skey_test_abc', $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_the_pay_page_reports_test_mode_so_it_is_never_mistaken_for_revenue(): void
    {
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);

        $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertJsonPath('data.gateway.mode', 'test');
    }

    public function test_an_order_with_money_already_taken_is_never_offered_a_card_form_again(): void
    {
        // `payment_received`, not `status`: the charge id is claimed BEFORE
        // the order is confirmed, and in the rare case where confirmation
        // fails it stays that way. A pending order with money taken must not
        // invite a second payment.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $order->forceFill(['gateway_charge_id' => 'chrg_already'])->save();

        $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertJsonPath('data.gateway.payment_received', true)
            ->assertJsonPath('data.gateway.intent', null);
    }

    public function test_a_manual_order_still_gets_its_promptpay_payload_and_no_card_intent(): void
    {
        // THE REGRESSION THAT WOULD HURT MOST: every company on this
        // platform is `manual` today and none has a settings row. If the new
        // gateway plumbing made a configured payment method depend on one,
        // every live pay page would stop working at once.
        $company = Company::factory()->create(['payment_promptpay_id' => '0812345678']);
        $order = $this->payableOrder($company, PaymentProvider::Manual);
        $order->forceFill(['payment_method' => PaymentMethod::PromptPay->value])->save();

        $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('data.gateway.provider', 'manual')
            ->assertJsonPath('data.gateway.intent', null)
            ->assertJsonPath('data.company_payment.promptpay_id', '0812345678');

        $this->assertNotSame('', $this->getJson("/api/v1/pay/{$order->public_token}")->json('data.promptpay_payload'));
    }

    // ── The webhook ──────────────────────────────────────────────────────

    public function test_an_unsigned_webhook_is_refused(): void
    {
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $body = $this->webhookBody(['id' => 'chrg_x', 'status' => 'successful', 'amount' => 890000, 'metadata' => ['order_token' => $order->public_token]]);

        $this->postWebhook($company, $body, signature: '')->assertStatus(401);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_a_forged_webhook_is_refused(): void
    {
        // The whole reason this endpoint is not simply "public": with a
        // permissive path here, anyone on the internet gets free goods AND
        // an agent gets an immutable commission row for money never sent.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $body = $this->webhookBody(['id' => 'chrg_x', 'status' => 'successful', 'amount' => 890000, 'metadata' => ['order_token' => $order->public_token]]);

        $this->postWebhook($company, $body, signature: hash_hmac('sha256', $body, 'whsec_attacker_guess'))
            ->assertStatus(401);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_a_signature_over_different_content_is_refused(): void
    {
        // Signing the RIGHT body with the right key and then posting a
        // different one — the attack a naive "is there a signature header"
        // check would let through.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $honest = $this->webhookBody(['id' => 'chrg_x', 'status' => 'failed', 'amount' => 100, 'metadata' => ['order_token' => $order->public_token]]);
        $tampered = $this->webhookBody(['id' => 'chrg_x', 'status' => 'successful', 'amount' => 890000, 'metadata' => ['order_token' => $order->public_token]]);

        $this->postWebhook($company, $tampered, signature: hash_hmac('sha256', $honest, self::SECRET))
            ->assertStatus(401);

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    public function test_a_valid_webhook_confirms_the_order(): void
    {
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $body = $this->webhookBody(['id' => 'chrg_ok', 'status' => 'successful', 'amount' => 890000, 'metadata' => ['order_token' => $order->public_token]]);

        $this->postWebhook($company, $body)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('chrg_ok', $order->gateway_charge_id);
        $this->assertDatabaseCount('commission_ledger', 1);
    }

    public function test_the_same_webhook_delivered_twice_writes_one_ledger_row(): void
    {
        // NOT an edge case. Gateways retry as a matter of course, and Omise
        // re-sends any event it did not see a 2xx for. Two rows here is an
        // agent paid twice for one sale, in a table that may never be edited.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $body = $this->webhookBody(['id' => 'chrg_dup', 'status' => 'successful', 'amount' => 890000, 'metadata' => ['order_token' => $order->public_token]]);

        $this->postWebhook($company, $body)->assertOk();
        $this->postWebhook($company, $body)->assertOk();

        $this->assertDatabaseCount('commission_ledger', 1);
        $this->assertSame(1, Order::withoutGlobalScopes()->where('gateway_charge_id', 'chrg_dup')->count());
    }

    public function test_a_webhook_arriving_after_the_synchronous_charge_changes_nothing(): void
    {
        // The real-world sequence: the charge succeeds inside the customer's
        // own request, and Omise announces the same charge again seconds
        // later. Both paths must be indistinguishable to the code that
        // confirms orders, which is why charge() returns a WebhookOutcome.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $this->fakeChargeSuccess('chrg_race');

        $this->postJson("/api/v1/pay/{$order->public_token}/charge", ['payment_token' => 'tokn_test_abc'])->assertOk();

        $body = $this->webhookBody(['id' => 'chrg_race', 'status' => 'successful', 'amount' => 890000, 'metadata' => ['order_token' => $order->public_token]]);
        $this->postWebhook($company, $body)->assertOk();

        $this->assertDatabaseCount('commission_ledger', 1);
    }

    public function test_a_webhook_claiming_the_wrong_amount_is_refused(): void
    {
        // A ฿1 charge confirming an ฿8,900 order would pay commission on
        // eight thousand baht nobody sent.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $body = $this->webhookBody(['id' => 'chrg_short', 'status' => 'successful', 'amount' => 100, 'metadata' => ['order_token' => $order->public_token]]);

        $this->postWebhook($company, $body)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->gateway_charge_id, 'a mismatched amount must not even claim the charge id');
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_a_webhook_cannot_reach_another_companys_order(): void
    {
        // BR-6 is not a rule about what is guessable. A signed event from
        // company A's own account must not touch company B's orders.
        $companyA = $this->omiseCompany();
        $companyB = $this->omiseCompany();
        $orderB = $this->payableOrder($companyB);

        $body = $this->webhookBody(['id' => 'chrg_cross', 'status' => 'successful', 'amount' => 890000, 'metadata' => ['order_token' => $orderB->public_token]]);

        // Signed correctly FOR COMPANY A — the signature is genuine, the
        // reach is not.
        $this->postWebhook($companyA, $body)->assertOk();

        $orderB->refresh();
        $this->assertSame(OrderStatus::Pending, $orderB->status);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_an_unknown_company_or_provider_gets_a_flat_404(): void
    {
        // A distinct error would let anyone probe this endpoint to enumerate
        // which company ids exist and which of them take card payments.
        $company = $this->omiseCompany();
        $body = $this->webhookBody(['id' => 'chrg_x', 'status' => 'successful']);

        $this->postWebhook($company, $body, provider: 'not_a_provider')->assertNotFound();

        $this->call('POST', '/api/v1/webhooks/payments/omise/999999', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_OMISE_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertNotFound();
    }

    public function test_a_company_with_no_omise_configuration_has_no_webhook_endpoint(): void
    {
        // Not 401 — 404. There is no secret to check against, so there is
        // nothing here at all.
        $company = Company::factory()->create();
        $body = $this->webhookBody(['id' => 'chrg_x', 'status' => 'successful']);

        $this->postWebhook($company, $body)->assertNotFound();
    }

    public function test_an_uninteresting_event_is_acknowledged_and_ignored(): void
    {
        // Ignore is a first-class outcome. Omise emits many event types, and
        // erroring on the uninteresting ones produces retry storms plus
        // alarms nobody can act on.
        $company = $this->omiseCompany();
        $body = $this->webhookBody(['id' => 'cust_1'], key: 'customer.create');

        $this->postWebhook($company, $body)->assertOk()->assertJsonPath('message', 'ignored');
    }

    public function test_a_refund_is_recorded_and_never_reverses_commission_by_itself(): void
    {
        // Reversing a sale means clawing back an agent's commission, and BR-4
        // rows are immutable — the reversal is its own entry with its own
        // rules. That stays a human decision; a webhook must not make it.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $body = $this->webhookBody(
            ['id' => 'chrg_ref', 'amount' => 890000, 'metadata' => ['order_token' => $order->public_token]],
            key: 'refund.create',
        );

        $this->postWebhook($company, $body)->assertOk();

        $this->assertNull($order->refresh()->refunded_at);
    }

    public function test_a_failed_webhook_never_cancels_the_order(): void
    {
        // The customer will very likely try another card in the next thirty
        // seconds, and an order cancelled underneath them turns a retry into
        // a dead link.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $body = $this->webhookBody([
            'id' => 'chrg_fail',
            'status' => 'failed',
            'amount' => 890000,
            'failure_message' => 'insufficient_fund',
            'metadata' => ['order_token' => $order->public_token],
        ]);

        $this->postWebhook($company, $body)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->gateway_charge_id);
    }

    // ── The service's own guards, reached directly ───────────────────────

    public function test_a_paid_outcome_with_no_charge_id_is_refused(): void
    {
        // There would be nothing to make idempotent. Confirming without one
        // means the next delivery of the same event confirms again.
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);

        $result = app(GatewayPaymentService::class)->applyPaid(
            $order,
            new WebhookOutcome(result: WebhookResult::Paid, chargeId: null, amountSatang: 890000),
        );

        $this->assertNull($result);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    public function test_a_charge_id_already_used_by_another_order_cannot_be_reused(): void
    {
        // The UNIQUE index is the second line of defence, for the case where
        // the same charge id arrives naming two different orders.
        $company = $this->omiseCompany();
        $first = $this->payableOrder($company);
        $second = $this->payableOrder($company);

        $first->forceFill(['gateway_charge_id' => 'chrg_shared'])->save();

        $result = app(GatewayPaymentService::class)->applyPaid(
            $second,
            new WebhookOutcome(result: WebhookResult::Paid, chargeId: 'chrg_shared', amountSatang: 890000),
        );

        $this->assertNull($result);
        $this->assertSame(OrderStatus::Pending, $second->refresh()->status);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_a_second_charge_id_cannot_overwrite_the_one_already_on_file(): void
    {
        /*
         * THIS CASE EXISTS BECAUSE MUTATION TESTING PROVED THE OTHERS DID NOT
         * COVER GUARD 2.
         *
         * The duplicate-webhook case above stays green even with the
         * conditional `whereNull` removed, because by the second delivery the
         * order is already Paid and the PHP status check catches it. That
         * check is not the guard — it is the thing the guard exists to be
         * independent of, since two webhooks arriving at once both read
         * "not paid" before either writes.
         *
         * SQLite cannot stage that race (as SecurityAuditProofTest notes for
         * lockForUpdate). What CAN be staged is the state the race would
         * produce and that this system deliberately allows: an order with
         * money taken — a charge id on file — that is NOT yet Paid, because
         * confirmation failed. A second event must not overwrite the receipt
         * of the first.
         *
         * Remove `whereNull('gateway_charge_id')` from the claim and this
         * fails; nothing else in this file does.
         */
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $order->forceFill(['gateway_charge_id' => 'chrg_first'])->save();

        $result = app(GatewayPaymentService::class)->applyPaid(
            $order,
            new WebhookOutcome(result: WebhookResult::Paid, chargeId: 'chrg_second', amountSatang: 890000),
        );

        $this->assertNull($result);
        $this->assertSame('chrg_first', $order->refresh()->gateway_charge_id);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_a_cancelled_order_is_never_confirmed_by_a_late_webhook(): void
    {
        $company = $this->omiseCompany();
        $order = $this->payableOrder($company);
        $order->forceFill(['status' => OrderStatus::Cancelled->value])->save();

        $body = $this->webhookBody(['id' => 'chrg_late', 'status' => 'successful', 'amount' => 890000, 'metadata' => ['order_token' => $order->public_token]]);
        $this->postWebhook($company, $body)->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    // ── Finding the orders where money arrived and nothing finished ──────

    public function test_an_order_with_money_taken_but_not_confirmed_is_findable_by_staff(): void
    {
        /*
         * THE RESIDUE THE DESIGN DELIBERATELY LEAVES.
         *
         * applyPaid() catches a confirmation that refuses rather than letting
         * it become a webhook retry loop or a "payment failed" message shown
         * to somebody who has just been charged. That is right, and it leaves
         * an order holding a receipt for money the system could not finish
         * acting on.
         *
         * "A human resolves it" is only true if a human can find it. This is
         * that query, and this case is what stops it being quietly dropped as
         * an unused filter later.
         */
        $company = $this->omiseCompany();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $stranded = $this->payableOrder($company);
        $stranded->forceFill(['gateway_charge_id' => 'chrg_stranded'])->save();

        // Two orders that must NOT appear: an ordinary unpaid one with no
        // money taken, and a gateway order that completed normally.
        $this->payableOrder($company);
        $settled = $this->payableOrder($company);
        $settled->forceFill(['gateway_charge_id' => 'chrg_done', 'status' => OrderStatus::Paid->value])->save();

        $response = $this->actingAs($admin)->getJson('/api/v1/orders?needs_attention=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($stranded->id, $response->json('data.0.id'));
    }

    public function test_the_summary_counts_the_stranded_orders_alongside_the_statuses(): void
    {
        // On the summary rather than its own endpoint: the tab bar must know
        // whether to show this tab before anybody clicks, at the same moment
        // it learns every other count.
        $company = $this->omiseCompany();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $stranded = $this->payableOrder($company);
        $stranded->forceFill(['gateway_charge_id' => 'chrg_stranded'])->save();

        // A gateway order that COMPLETED. Mutation testing caught this
        // missing: without it, a count that forgot to exclude paid orders
        // still returned 1 and the case passed while proving nothing.
        $settled = $this->payableOrder($company);
        $settled->forceFill(['gateway_charge_id' => 'chrg_done', 'status' => OrderStatus::Paid->value])->save();

        $this->actingAs($admin)->getJson('/api/v1/orders/summary')
            ->assertOk()
            ->assertJsonPath('needs_attention', 1);
    }

    public function test_the_summary_reports_zero_rather_than_omitting_the_key(): void
    {
        // A tab that disappears when its queue empties cannot be told apart
        // from a tab that broke.
        $company = $this->omiseCompany();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->payableOrder($company);

        $this->actingAs($admin)->getJson('/api/v1/orders/summary')
            ->assertOk()
            ->assertJsonPath('needs_attention', 0);
    }

    public function test_the_order_list_tells_staff_a_machine_confirmed_the_payment(): void
    {
        // `has_slip` answers "is there proof a person must judge". This
        // answers "is there proof a machine already produced". A screen that
        // showed only the first would present a card payment as unevidenced.
        $company = $this->omiseCompany();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $order = $this->payableOrder($company);
        $order->forceFill(['gateway_charge_id' => 'chrg_visible'])->save();

        $response = $this->actingAs($admin)->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonPath('data.0.gateway_payment_received', true)
            ->assertJsonPath('data.0.payment_provider', 'omise')
            ->assertJsonPath('data.0.gateway_mode', 'test');

        // The charge id can mark an order paid. Staff cannot act on it, so it
        // is not in the payload at all.
        $this->assertStringNotContainsString('chrg_visible', $response->getContent());
    }

    // ── The stamp on the order ───────────────────────────────────────────

    public function test_an_order_is_stamped_with_the_companys_gateway_at_creation(): void
    {
        $company = $this->omiseCompany(isLive: false);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 500000]);
        $referral = Referral::create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::Finish1stDoctorMeeting,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);

        $order = app(OrderService::class)
            ->createForReferral($referral, PaymentMethod::BankTransfer);

        $this->assertSame(PaymentProvider::Omise, $order->payment_provider);
        // Recorded per ORDER: a charge made with a test key must not look
        // like revenue in any report.
        $this->assertSame('test', $order->gateway_mode);
    }

    public function test_a_company_that_never_opened_the_settings_screen_still_reads_back_as_manual(): void
    {
        /*
         * Every company on this platform is in exactly this state right now:
         * `payment_provider` at its 'manual' default and no settings row,
         * because the screen that writes one did not exist until this work.
         *
         * "No configuration" would be a false statement about a company that
         * takes money by bank transfer every day. Nothing acts on that answer
         * incorrectly TODAY — mutation testing proved that much — so this
         * pins the answer rather than the branch producing it, for the next
         * caller that does.
         */
        $company = Company::factory()->create();

        $config = app(CompanyPaymentGatewayService::class)->activeConfig($company);

        $this->assertNotNull($config);
        $this->assertSame(PaymentProvider::Manual, $config['provider']);
        $this->assertSame([], $config['credentials']);
    }

    public function test_an_order_for_a_company_with_no_gateway_is_stamped_manual(): void
    {
        // Fails closed onto the flow that cannot take money by mistake.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 500000]);
        $referral = Referral::create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::Finish1stDoctorMeeting,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);

        $order = app(OrderService::class)
            ->createForReferral($referral, PaymentMethod::BankTransfer);

        $this->assertSame(PaymentProvider::Manual, $order->payment_provider);
    }
}
