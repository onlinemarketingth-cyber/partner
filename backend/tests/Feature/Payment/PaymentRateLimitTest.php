<?php

namespace Tests\Feature\Payment;

use App\Enums\PipelineStage;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-11 (human, stopped mid-payment: "Too Many Attempts. ขึ้นแบบนี้แก้ไข
 * อย่างไร").
 *
 * ── THE BUG ──
 *
 * The /pay/{token} routes are unauthenticated by design — the token IS the
 * credential (ADR-011) — and `throttle:10,1` on an unauthenticated route keys
 * on the IP ADDRESS. So the limit was never really about the order: every
 * customer arriving from one address shared a single bucket. An office, a
 * hotel, a seminar room, or any Thai carrier doing CGNAT, where thousands of
 * phones leave through a handful of addresses.
 *
 * The worst version of that is not hypothetical: at an event where twenty
 * people buy at once, the eleventh is refused on their FIRST tap, holding
 * their money out, and nobody watching can tell why.
 *
 * The limits are now keyed on the order token, with a looser per-IP ceiling
 * behind them — see AppServiceProvider::definePublicPaymentRateLimits.
 *
 * ── AND WHAT IT SAID WHEN IT REFUSED ──
 *
 * "Too Many Attempts." — Laravel's English default, on a Thai payment page,
 * with no wait and no next step, so the only move it offers is to press the
 * button again and extend the window that is blocking you.
 */
class PaymentRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_customers_retries_never_block_another_customer(): void
    {
        /*
         * THE ONE THAT MATTERS. Two different orders, same IP — which is
         * every pair of customers on one office WiFi. The first burns its own
         * allowance; the second must still be able to pay.
         */
        $busy = $this->payableOrder();
        $other = $this->payableOrder();

        for ($i = 0; $i < 12; $i++) {
            $this->postJson("/api/v1/pay/{$busy->public_token}/intent", []);
        }

        // Refused, as intended — this order really was hammered.
        $this->postJson("/api/v1/pay/{$busy->public_token}/intent", [])->assertStatus(429);

        // And the customer beside them is untouched. Any answer but 429 is a
        // pass: the gateway is not configured in this test, so what comes
        // back is a refusal about payment methods rather than about limits.
        $this->postJson("/api/v1/pay/{$other->public_token}/intent", [])->assertStatus(422);
    }

    public function test_reading_the_page_is_not_rationed_the_way_paying_is(): void
    {
        // Twenty people opening their own links from one venue. Before this,
        // the shared per-IP budget made that a race.
        for ($i = 0; $i < 20; $i++) {
            $order = $this->payableOrder();
            $this->getJson("/api/v1/pay/{$order->public_token}")->assertOk();
        }
    }

    public function test_the_refusal_is_in_thai_and_says_how_long_to_wait(): void
    {
        /*
         * The limit itself is correct behaviour; the ENGLISH is the bug. A
         * customer who cannot read the refusal has only one move — press it
         * again — which extends the window that is refusing them.
         */
        $order = $this->payableOrder();

        for ($i = 0; $i < 12; $i++) {
            $this->postJson("/api/v1/pay/{$order->public_token}/intent", []);
        }

        $response = $this->postJson("/api/v1/pay/{$order->public_token}/intent", [])->assertStatus(429);

        $message = (string) $response->json('message');

        $this->assertStringNotContainsString('Too Many Attempts', $message);
        $this->assertStringContainsString('ถี่เกินไป', $message);
        $this->assertStringContainsString('วินาที', $message);

        // Retry-After survives the rewrite: the agent portal's sign-up screen
        // counts down from this header, and ApiError.retryAfterSeconds reads
        // it. Rewording the body must not cost the clients their number.
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    /** An order whose public pay page is live. */
    private function payableOrder(): Order
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
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

        return Order::factory()->create(['referral_id' => $referral->id]);
    }
}
