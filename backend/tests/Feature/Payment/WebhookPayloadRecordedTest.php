<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentProvider;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyPaymentGatewaySetting;
use App\Models\Order;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 2026-09-11 (human: "ขึ้นค่า debug จริงว่า stripe คืนค่าอะไรมา จะได้รู้ปัญหา
 * เกิดจากอะไร").
 *
 * The system could describe every payment decision it had made and could not
 * show a single thing Stripe had actually said. Those are different kinds of
 * evidence, and when a decision looks wrong only the second kind settles it —
 * reading our own conclusion back proves nothing about how we reached it.
 *
 * What these tests hold to:
 *
 *   1. a verified delivery is kept VERBATIM, including the field the decision
 *      turns on (`payment_status`) and the event types we do nothing with;
 *   2. a delivery that matches NO order is kept too — that is the case with no
 *      other trace anywhere;
 *   3. an UNVERIFIED body is never written, because a table anyone can write
 *      into is a place to put an attack rather than find one;
 *   4. secrets in the body are redacted before storage;
 *   5. recording can never break the payment path it was added to explain.
 */
class WebhookPayloadRecordedTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    public function test_the_payload_is_kept_exactly_as_stripe_sent_it(): void
    {
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'id' => 'evt_test_paid',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_paid',
                'payment_intent' => 'pi_test_paid',
                'payment_status' => 'paid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ])->assertOk();

        $event = PaymentWebhookEvent::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('evt_test_paid', $event->event_id);
        $this->assertSame('checkout.session.completed', $event->event_type);
        $this->assertSame($order->id, $event->order_id);
        $this->assertSame('paid', $event->result);
        $this->assertSame('pi_test_paid', $event->charge_id);

        // THE FIELD THE WHOLE QUESTION TURNS ON. The event type is the same
        // for a declined card; `payment_status` is what separates them, so it
        // is the one that must survive into the record.
        $this->assertSame('paid', data_get($event->payload, 'data.object.payment_status'));
    }

    public function test_a_declined_card_leaves_the_refusal_in_writing(): void
    {
        /*
         * The report that started this: "ทดสอบไม่สำเร็จแต่ยังขึ้นสำเร็จ". With
         * the payload on file, that question is answerable from the database
         * instead of by reasoning about what Stripe probably sent.
         */
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'id' => 'evt_test_declined',
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_test_declined',
                'metadata' => ['order_token' => $order->public_token],
                'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds'],
            ]],
        ])->assertOk();

        $event = PaymentWebhookEvent::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('failed', $event->result);
        $this->assertSame('insufficient_funds', data_get($event->payload, 'data.object.last_payment_error.decline_code'));
    }

    public function test_an_event_this_system_ignores_is_still_written_down(): void
    {
        /*
         * "Stripe never sent it" and "Stripe sent it and we ignored it" look
         * identical from the inside and need opposite fixes — a dashboard
         * subscription versus a missing handler here. Only a record of the
         * arrival tells them apart.
         */
        [$company] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'id' => 'evt_unhandled',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_123']],
        ])->assertOk();

        $event = PaymentWebhookEvent::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('customer.subscription.updated', $event->event_type);
        $this->assertSame('ignore', $event->result);
        $this->assertNull($event->order_id);
    }

    public function test_a_payment_matching_no_order_is_kept_above_all(): void
    {
        /*
         * THE ONE WITH NO OTHER TRACE. A signed event says money moved and
         * names a token no order holds: the customer has been charged and
         * nothing downstream will ever know. There is no order to attach the
         * evidence to, so without this row the payload is gone.
         */
        [$company] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'id' => 'evt_orphan',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_orphan',
                'payment_intent' => 'pi_orphan',
                'payment_status' => 'paid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => 'a-token-no-order-has'],
            ]],
        ])->assertOk();

        $event = PaymentWebhookEvent::withoutGlobalScopes()->firstOrFail();

        $this->assertNull($event->order_id);
        $this->assertSame(PaymentWebhookEvent::RESULT_UNMATCHED, $event->result);
        $this->assertSame('a-token-no-order-has', $event->order_token);
    }

    public function test_an_unsigned_body_is_never_written_anywhere(): void
    {
        /*
         * THE ONE THAT KEEPS THIS TABLE SAFE TO READ. The body of a rejected
         * request is chosen by whoever sent it. Storing it would give anyone
         * on the internet a write into this database and put attacker-authored
         * text in front of whoever next investigates a payment — the exact
         * place a person is least prepared to distrust what they are reading.
         */
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'id' => 'evt_forged',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_forged',
                'payment_status' => 'paid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ], signature: 'v1=deadbeef')->assertStatus(401);

        $this->assertSame(0, PaymentWebhookEvent::withoutGlobalScopes()->count());
    }

    public function test_a_secret_in_the_body_is_not_what_gets_kept(): void
    {
        /*
         * Stripe puts `client_secret` on a PaymentIntent, and it is enough to
         * confirm that intent from a browser. Section 6's rule — a credential
         * never leaves the service holding it — has no exemption for a
         * debugging table.
         *
         * The KEY survives so a reader can see it was there; only the value
         * is replaced.
         */
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'id' => 'evt_secret',
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => [
                'id' => 'pi_secret',
                'client_secret' => 'pi_secret_secret_abc123',
                'metadata' => ['order_token' => $order->public_token],
                'last_payment_error' => ['decline_code' => 'generic_decline'],
            ]],
        ])->assertOk();

        $event = PaymentWebhookEvent::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('[redacted]', data_get($event->payload, 'data.object.client_secret'));
        $this->assertStringNotContainsString('pi_secret_secret_abc123', json_encode($event->payload));
        // And the surrounding evidence is untouched — a redaction that ate
        // the payload would defeat the point of keeping it.
        $this->assertSame('generic_decline', data_get($event->payload, 'data.object.last_payment_error.decline_code'));
    }

    public function test_recording_never_decides_whether_a_payment_is_confirmed(): void
    {
        // The diagnostic must not be able to break the thing it explains. If
        // the table were missing entirely — a migration not yet run on the
        // server — the money path still has to work.
        [$company, $order] = $this->stripeOrder();

        Schema::drop('payment_webhook_events');

        $this->postStripeWebhook($company, [
            'id' => 'evt_no_table',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_no_table',
                'payment_intent' => 'pi_no_table',
                'payment_status' => 'paid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ])->assertOk();

        $this->assertSame('pi_no_table', $order->fresh()->gateway_charge_id);
    }

    // ── The commands that read it ────────────────────────────────────

    public function test_the_explain_command_prints_what_the_gateway_said(): void
    {
        /*
         * A diagnostic is only ever run in an emergency, by somebody who has
         * no time to debug the diagnostic. A typo in it would be found at the
         * worst possible moment, so the command is exercised here rather than
         * trusted.
         */
        [$company, $order] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'id' => 'evt_explain',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_explain',
                'payment_intent' => 'pi_explain',
                'payment_status' => 'paid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => $order->public_token],
            ]],
        ])->assertOk();

        // Without --raw: the summary line, with the deciding field lifted out
        // of the body so it is readable at a glance.
        $this->artisan('orders:explain', ['order' => $order->order_number])
            ->expectsOutputToContain('checkout.session.completed')
            ->assertSuccessful();

        /*
         * With --raw: the body itself, as it arrived.
         *
         * ONE substring per run, deliberately. Each expectation is matched
         * against whole writes, and the JSON block contains the event type
         * too — so two expectations in one run would both be answered by the
         * same write and the second would be reported as missing. (Which is
         * exactly what happened while writing this file.)
         */
        $this->artisan('orders:explain', ['order' => $order->order_number, '--raw' => true])
            ->expectsOutputToContain('"payment_status": "paid"')
            ->assertSuccessful();
    }

    public function test_the_log_command_finds_a_payment_that_matched_no_order(): void
    {
        [$company] = $this->stripeOrder();

        $this->postStripeWebhook($company, [
            'id' => 'evt_orphan_cli',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_orphan_cli',
                'payment_intent' => 'pi_orphan_cli',
                'payment_status' => 'paid',
                'amount_total' => 890000,
                'metadata' => ['order_token' => 'nothing-here'],
            ]],
        ])->assertOk();

        $this->artisan('payments:webhook-log', ['--unmatched' => true])
            ->expectsOutputToContain('ไม่พบคำสั่งซื้อที่ตรงกัน')
            ->assertSuccessful();
    }

    public function test_the_log_command_says_plainly_when_nothing_arrived(): void
    {
        // The empty answer is itself the finding — "no webhook ever reached
        // us" is the most common cause of "I paid and nothing happened", and
        // it must not look like the command failing.
        $this->artisan('payments:webhook-log')
            ->expectsOutputToContain('ไม่มีเหตุการณ์จากเกตเวย์')
            ->assertSuccessful();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /** @param array<string, mixed> $event */
    private function postStripeWebhook(Company $company, array $event, ?string $signature = null): TestResponse
    {
        $body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
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

    /** @return array{0: Company, 1: Order} */
    private function stripeOrder(): array
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

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);

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
