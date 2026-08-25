<?php

namespace Tests\Feature\Customer;

use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Opening a client answers "have they paid?".
 *
 * ── THE BUG, REPORTED 2026-08-22 ──
 *
 * "ผมเช็คได้ยังไงว่าลูกค้าคนนี้อยู่ในสถานะใด จ่ายเงินหรือยัง รอทำอะไร
 * ในหน้าเดียว" — an admin opening a customer saw a sales stage and no way to
 * tell whether the money had arrived.
 *
 * ── WHY NOTHING LOOKED BROKEN ──
 *
 * ReferralResource has carried a full `order` block since TASK-190: status,
 * amount, paid_at, has_slip, who verified it. It is wrapped in
 * `whenLoaded('orders')`, and ClientController::RELATIONS did not include
 * `orders`. So the key was not null and not empty — it was ABSENT, which is
 * exactly what `whenLoaded` is designed to do and therefore looked correct
 * from every side. The resource was complete, the controller was consistent,
 * and the feature did not exist.
 *
 * That is the failure mode this file pins: a relation quietly dropped from
 * an eager-load list is invisible in review, invisible at runtime, and turns
 * a working payload key into nothing at all. No test asserted on `order`
 * from THIS endpoint, so the suite could not notice either.
 *
 * The cases below assert the three payment states that change what somebody
 * actually does, because "the key is present" would pass while carrying the
 * wrong answer.
 */
class ClientPaymentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Client, 2: Referral} */
    private function clientWithReferral(): array
    {
        $company = Company::factory()->create();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $agent->id,
        ]);
        $referral = Referral::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
        ]);

        return [$admin, $client, $referral];
    }

    public function test_a_paid_order_is_visible_on_the_client(): void
    {
        [$admin, $client, $referral] = $this->clientWithReferral();

        $order = Order::factory()->create([
            'referral_id' => $referral->id,
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.referrals.0.order.id', $order->id)
            ->assertJsonPath('data.referrals.0.order.order_number', $order->order_number)
            ->assertJsonPath('data.referrals.0.order.amount_satang', $order->amount_satang);

        $paidAt = $this->actingAs($admin)
            ->getJson("/api/v1/clients/{$client->id}")
            ->json('data.referrals.0.order.paid_at');

        $this->assertNotNull($paidAt, 'A paid order must report WHEN, not just that an order exists.');
    }

    public function test_an_unpaid_order_reports_itself_as_unpaid(): void
    {
        // The distinction the admin acts on: an order exists, so the customer
        // has been asked for money and has not sent it.
        [$admin, $client, $referral] = $this->clientWithReferral();

        Order::factory()->create(['referral_id' => $referral->id, 'paid_at' => null]);

        $response = $this->actingAs($admin)->getJson("/api/v1/clients/{$client->id}")->assertOk();

        $this->assertNull($response->json('data.referrals.0.order.paid_at'));
        $this->assertFalse($response->json('data.referrals.0.order.has_slip'));
    }

    public function test_an_attached_slip_is_visible_so_somebody_can_verify_it(): void
    {
        /*
         * The state that most needs surfacing: the customer says they paid,
         * and until an agent looks at the slip the deal cannot move. The
         * admin UI renders this in amber, NOT green — a slip is a claim of
         * payment, and showing it as settled is how an unverified transfer
         * gets treated as money in.
         */
        [$admin, $client, $referral] = $this->clientWithReferral();

        Order::factory()->create([
            'referral_id' => $referral->id,
            'paid_at' => null,
            'slip_path' => 'slips/whatever.jpg',
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.referrals.0.order.has_slip', true)
            ->assertJsonPath('data.referrals.0.order.paid_at', null);
    }

    public function test_a_referral_with_no_order_says_so_explicitly(): void
    {
        /*
         * `null`, not absent. The frontend keeps those two apart on purpose:
         * absent means nobody asked the database, and reporting that as
         * "not paid yet" would be a confident wrong answer. Only a real null
         * may render as "ยังไม่มีคำสั่งซื้อ".
         */
        [$admin, $client] = $this->clientWithReferral();

        $response = $this->actingAs($admin)->getJson("/api/v1/clients/{$client->id}")->assertOk();

        $this->assertArrayHasKey('order', $response->json('data.referrals.0'));
        $this->assertNull($response->json('data.referrals.0.order'));
    }

    public function test_saving_an_edit_does_not_blank_the_payment_block(): void
    {
        /*
         * The Admin modal re-renders from the UPDATE response, not from a
         * refetch. update() therefore has to load the same relations show()
         * does — otherwise editing a client's name would make the payment
         * section vanish until the next full reload, which reads as "the
         * order was deleted".
         */
        [$admin, $client, $referral] = $this->clientWithReferral();
        Order::factory()->create(['referral_id' => $referral->id, 'paid_at' => now()]);

        $this->actingAs($admin)
            ->putJson("/api/v1/clients/{$client->id}", ['name' => 'ชื่อใหม่'])
            ->assertOk()
            ->assertJsonPath('data.name', 'ชื่อใหม่')
            ->assertJsonPath('data.referrals.0.order.order_number', Order::first()->order_number);
    }

    public function test_the_client_list_stays_lean(): void
    {
        /*
         * index() lists every client in the company and renders no order
         * block. Loading orders there would grow the payload for nothing on
         * the one endpoint that scales with company size — the reason
         * DETAIL_RELATIONS is separate from RELATIONS rather than a fourth
         * entry in it.
         */
        [$admin, $client, $referral] = $this->clientWithReferral();
        Order::factory()->create(['referral_id' => $referral->id]);

        $row = $this->actingAs($admin)
            ->getJson('/api/v1/clients')
            ->assertOk()
            ->json('data.0.referrals.0');

        $this->assertArrayNotHasKey('order', $row, 'The list must not carry order data it never renders.');
        $this->assertSame($client->id, $this->actingAs($admin)->getJson('/api/v1/clients')->json('data.0.id'));
        $this->assertSame($referral->id, $row['id']);
    }
}
