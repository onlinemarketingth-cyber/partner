<?php

namespace Tests\Feature\Order;

use App\Enums\CommissionRateType;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-176 §1.6 — the `order` key on ReferralResource (the read path the
 * admin pipeline board's "รับชำระเงินแล้ว" button needs) and `verified_by`
 * on OrderResource.
 *
 * The point of the feature is that there stays exactly ONE door to the BR-4
 * ledger: `advance` and `confirm` must never both book a commission for the
 * same referral. Two of these tests exist purely to hold that line.
 */
class ReferralOrderTest extends TestCase
{
    use RefreshDatabase;

    /** A certified agent + a referral at $stage, with a commission rule so a sale close can pay out. */
    private function makeReferral(Company $company, User $agent, PipelineStage $stage): Referral
    {
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
            'rate_value' => 300, // 3%
        ]);

        return Referral::create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => 'Silom',
            'preferred_time' => now()->addDay(),
            'current_stage' => $stage,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);
    }

    public function test_a_referral_with_a_pending_order_exposes_it_on_the_board(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($admin)
            ->getJson('/api/v1/referrals')
            ->assertOk()
            ->assertJsonPath('data.0.order.id', $order->id)
            ->assertJsonPath('data.0.order.order_number', $order->order_number)
            ->assertJsonPath('data.0.order.status', 'awaiting_verification')
            ->assertJsonPath('data.0.order.status_label', OrderStatus::AwaitingVerification->label())
            // BR-3 — integer satang on the wire, never a baht float.
            ->assertJsonPath('data.0.order.amount_satang', 890000)
            ->assertJsonPath('data.0.order.has_slip', true)
            ->assertJsonPath('data.0.order.paid_at', null)
            ->assertJsonPath('data.0.order.verified_by', null);
    }

    public function test_a_referral_whose_only_order_is_cancelled_exposes_a_null_order(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        Order::factory()->create(['referral_id' => $referral->id, 'status' => OrderStatus::Cancelled]);

        $this->actingAs($admin)
            ->getJson('/api/v1/referrals')
            ->assertOk()
            ->assertJsonPath('data.0.order', null);
    }

    /** A referral with NO order at all still renders — the key is present and null (§4.5). */
    public function test_a_referral_with_no_order_exposes_a_null_order(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);

        $this->actingAs($admin)
            ->getJson('/api/v1/referrals')
            ->assertOk()
            ->assertJsonPath('data.0.order', null);
    }

    /** §1.2 — a live order outranks a paid one, and a cancelled one is never picked. */
    public function test_a_live_order_is_preferred_over_a_paid_or_cancelled_one(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);

        Order::factory()->create(['referral_id' => $referral->id, 'status' => OrderStatus::Cancelled]);
        Order::factory()->paid()->create(['referral_id' => $referral->id]);
        $live = Order::factory()->create(['referral_id' => $referral->id, 'status' => OrderStatus::Pending]);

        $this->actingAs($admin)
            ->getJson('/api/v1/referrals')
            ->assertOk()
            ->assertJsonPath('data.0.order.id', $live->id)
            ->assertJsonPath('data.0.order.status', 'pending');
    }

    public function test_a_paid_order_exposes_the_confirming_admin_on_both_the_board_and_the_order(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        // §1.5 — OrderPolicy::confirm already admits a Company Admin; nothing
        // in this task widens it.
        $this->actingAs($admin)
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.verified_by.id', $admin->id)
            ->assertJsonPath('data.verified_by.name', $admin->name);

        $this->actingAs($admin)
            ->getJson('/api/v1/referrals')
            ->assertOk()
            ->assertJsonPath('data.0.order.status', 'paid')
            ->assertJsonPath('data.0.order.verified_by.id', $admin->id)
            ->assertJsonPath('data.0.order.verified_by.name', $admin->name);
    }

    /** BR-6 — company A's admin sees neither company B's referral nor its order, and cannot confirm it. */
    public function test_an_admin_never_sees_or_confirms_another_companys_order(): void
    {
        $companyB = Company::factory()->create();
        $agentB = User::factory()->agent()->create(['company_id' => $companyB->id]);
        $referralB = $this->makeReferral($companyB, $agentB, PipelineStage::Finish1stDoctorMeeting);
        $orderB = Order::factory()->create(['referral_id' => $referralB->id]);

        // Company A must have a referral + order OF ITS OWN. An empty-vs-empty
        // assertion here would pass just as happily if index() had stopped
        // returning anything at all, or if the `orders` eager-load had been
        // dropped — i.e. it would go green on the two regressions it exists to
        // catch. Asserting "exactly A's, and only A's" cannot (ag-qa finding,
        // TASK-176 review).
        $companyA = Company::factory()->create();
        $adminA = User::factory()->companyAdmin()->create(['company_id' => $companyA->id]);
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $referralA = $this->makeReferral($companyA, $agentA, PipelineStage::Finish1stDoctorMeeting);
        $orderA = Order::factory()->create(['referral_id' => $referralA->id]);

        $response = $this->actingAs($adminA)->getJson('/api/v1/referrals')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($referralA->id, $response->json('data.0.id'));
        $this->assertSame($orderA->id, $response->json('data.0.order.id'));
        $this->assertStringNotContainsString($orderB->order_number, $response->getContent());

        $this->actingAs($adminA)->postJson("/api/v1/orders/{$orderB->id}/confirm")->assertNotFound();
        $this->assertSame(OrderStatus::Pending, $orderB->fresh()->status);
    }

    /**
     * BR-6 / §1.5 — the new `order` key must not become a way for an Agent to
     * see a COLLEAGUE's order. It rides on the referral row, and index()
     * already narrows those to `agent_id = self`.
     */
    public function test_an_agent_never_sees_a_colleagues_order_through_the_new_key(): void
    {
        $company = Company::factory()->create();
        $mine = User::factory()->agent()->create(['company_id' => $company->id]);
        $theirs = User::factory()->agent()->create(['company_id' => $company->id]);

        $myReferral = $this->makeReferral($company, $mine, PipelineStage::Finish1stDoctorMeeting);
        $myOrder = Order::factory()->create(['referral_id' => $myReferral->id]);

        $theirReferral = $this->makeReferral($company, $theirs, PipelineStage::Finish1stDoctorMeeting);
        $theirOrder = Order::factory()->create(['referral_id' => $theirReferral->id]);

        $response = $this->actingAs($mine)->getJson('/api/v1/referrals')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($myOrder->id, $response->json('data.0.order.id'));
        $this->assertStringNotContainsString($theirOrder->order_number, $response->getContent());

        // And the same-company colleague still cannot reach it directly (IDOR).
        $this->actingAs($mine)->postJson("/api/v1/orders/{$theirOrder->id}/confirm")->assertForbidden();
    }

    /**
     * TASK-191 §1.1/§1.3 — `order.public_pay_url` on the board's ReferralResource
     * must be the SAME value OrderResource itself exposes for that order, for
     * both an unpaid and a paid order. The field's presence must not depend on
     * payment status — hiding/showing the share button is a frontend concern.
     */
    public function test_referral_order_public_pay_url_matches_order_resources_own_value_when_unpaid(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id, 'status' => OrderStatus::Pending]);

        $boardResponse = $this->actingAs($agent)->getJson('/api/v1/referrals')->assertOk();
        $orderResponse = $this->actingAs($agent)->getJson("/api/v1/orders/{$order->id}")->assertOk();

        $this->assertNotNull($boardResponse->json('data.0.order.public_pay_url'));
        $this->assertSame(
            $orderResponse->json('data.public_pay_url'),
            $boardResponse->json('data.0.order.public_pay_url'),
        );
    }

    public function test_referral_order_public_pay_url_matches_order_resources_own_value_when_paid(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->paid()->create(['referral_id' => $referral->id]);

        $boardResponse = $this->actingAs($agent)->getJson('/api/v1/referrals')->assertOk();
        $orderResponse = $this->actingAs($agent)->getJson("/api/v1/orders/{$order->id}")->assertOk();

        $this->assertSame('paid', $boardResponse->json('data.0.order.status'));
        $this->assertNotNull($boardResponse->json('data.0.order.public_pay_url'));
        $this->assertSame(
            $orderResponse->json('data.public_pay_url'),
            $boardResponse->json('data.0.order.public_pay_url'),
        );
    }

    /** BR-4 — confirming twice is a no-op the second time and leaves exactly one immutable ledger row. */
    public function test_confirming_twice_is_idempotent_and_writes_exactly_one_ledger_row(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($admin)->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(PipelineStage::CompletePayment, $referral->fresh()->current_stage);
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    /**
     * The §2 defect this task exists to close: advancing books the commission,
     * then confirming the still-`pending` order must close the customer's bill
     * WITHOUT booking a second one (OrderService::confirmPayment's
     * `alreadyClosed` branch — untouched by this task).
     */
    public function test_advancing_then_confirming_marks_the_order_paid_without_a_second_ledger_row(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($admin)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();

        $this->assertSame(PipelineStage::CompletePayment, $referral->fresh()->current_stage);
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        // The live payment page is still open at this point — that is the defect.
        $this->assertSame(OrderStatus::AwaitingVerification, $order->fresh()->status);

        $this->actingAs($admin)
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.verified_by.id', $admin->id);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame($admin->id, $order->verified_by_user_id);
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }
}
