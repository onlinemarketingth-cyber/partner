<?php

namespace Tests\Feature\Order;

use App\Enums\CommissionRateType;
use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Models\AuditLog;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// ADR-017 (TASK-054) — Order & Payment Collection. Covers order creation
// (§5 own-referral gate), the public token-gated pay page + slip upload
// (§6, no PDPA leak), payment confirmation firing BR-4 commission exactly
// from the §4.3-correct stage, cross-tenant isolation (BR-6), and the
// access-checked private-disk slip download.
class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);

        return $tier;
    }

    /** A certified agent + a referral at $stage, with a commission rule so a sale close can pay out. */
    private function makeReferral(Company $company, User $agent, PipelineStage $stage): Referral
    {
        $tier = $this->passBasicCert($agent, $company);
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

    public function test_agent_can_create_an_order_for_their_own_referral(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);

        $this->actingAs($agent)
            ->postJson('/api/v1/orders', ['referral_id' => $referral->id, 'payment_method' => 'bank_transfer'])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount_satang', 890000)
            ->assertJsonPath('data.referral_id', $referral->id);

        $this->assertDatabaseHas('orders', [
            'referral_id' => $referral->id,
            'agent_id' => $agent->id,
            'company_id' => $company->id,
            'status' => 'pending',
        ]);
        // A readable order number was generated.
        $this->assertStringStartsWith('ORD-', Order::withoutGlobalScopes()->where('referral_id', $referral->id)->value('order_number'));
    }

    public function test_agent_cannot_create_an_order_for_another_agents_referral(): void
    {
        $company = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $company->id]);
        $agentB = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agentB, PipelineStage::Finish1stDoctorMeeting);

        $this->actingAs($agentA)
            ->postJson('/api/v1/orders', ['referral_id' => $referral->id, 'payment_method' => 'bank_transfer'])
            ->assertUnprocessable(); // §5 rule 4 — StoreOrderRequest rejects a colleague's referral

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_public_pay_page_returns_the_order_without_auth_and_does_not_leak_agent_or_commission(): void
    {
        $company = Company::factory()->create([
            'payment_bank_name' => 'ธนาคารกสิกรไทย',
            'payment_bank_account_number' => '123-4-56789-0',
            'payment_bank_account_name' => 'บริษัท ไทยไลฟ์ จำกัด',
            'payment_promptpay_id' => '0812345678',
        ]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id, 'payment_method' => 'promptpay']);

        $response = $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('data.amount_satang', 890000)
            ->assertJsonPath('data.company_payment.bank_name', 'ธนาคารกสิกรไทย')
            ->assertJsonPath('data.company_payment.promptpay_id', '0812345678');

        // PromptPay payload was built (starts with the EMVCo format indicator).
        $this->assertStringStartsWith('00020101', $response->json('data.promptpay_payload'));

        // §6 — no agent/commission/referral/PDPA fields on the public view.
        $data = $response->json('data');
        $this->assertArrayNotHasKey('agent', $data);
        $this->assertArrayNotHasKey('agent_id', $data);
        $this->assertArrayNotHasKey('referral_id', $data);
        $this->assertArrayNotHasKey('amount_baht_commission', $data);
    }

    public function test_public_slip_upload_moves_the_order_to_awaiting_verification(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id]);

        $this->postJson("/api/v1/pay/{$order->public_token}/slip", [
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertOk()->assertJsonPath('data.status', 'awaiting_verification');

        $order->refresh();
        $this->assertSame(OrderStatus::AwaitingVerification, $order->status);
        $this->assertNotNull($order->slip_path);
        Storage::disk('local')->assertExists($order->slip_path);
    }

    /**
     * THE SELLING AGENT CAN LOOK AT THE SLIP THEIR CUSTOMER SENT.
     *
     * Reported 2026-08-21: "ลูกค้าแนบสลิปแล้วแต่ Agent เช็คไม่ได้". The
     * screens were the immediate cause — the client drawer and the pipeline
     * card both NAMED the slip and offered nothing to press — but the reason
     * this test exists is the layer underneath.
     *
     * The 2026-08-21 audit split confirm() out of ownsOrManages() so that an
     * agent can no longer verify their own payment (human ruling D1). That
     * was right, and it is exactly the kind of narrowing that gets applied
     * one method too far: tightening view() the same way would take the slip
     * away from the person who collected the money, silently, with no test
     * objecting. Confirming is a decision; LOOKING is not, and an agent
     * chasing a customer over an unpaid bill needs to see what was sent.
     */
    public function test_the_selling_agent_can_download_their_own_orders_slip(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id]);

        $this->postJson("/api/v1/pay/{$order->public_token}/slip", [
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertOk();

        $this->actingAs($agent)
            ->get("/api/v1/orders/{$order->id}/slip")
            ->assertOk();
    }

    public function test_an_agent_from_another_company_cannot_download_the_slip(): void
    {
        // BR-6. A payment slip carries a real person's bank account and the
        // amount they paid; the tenant boundary is the whole protection.
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id]);

        $this->postJson("/api/v1/pay/{$order->public_token}/slip", [
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertOk();

        $outsider = User::factory()->agent()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($outsider)
            ->get("/api/v1/orders/{$order->id}/slip")
            ->assertNotFound();
    }

    public function test_an_order_with_no_slip_404s_rather_than_streaming_nothing(): void
    {
        // The UI hides the button on has_slip = false; this is the half that
        // holds when something reaches the URL anyway.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id]);

        $this->actingAs($agent)
            ->get("/api/v1/orders/{$order->id}/slip")
            ->assertNotFound();
    }

    public function test_confirming_from_finish_1st_doctor_meeting_pays_the_order_and_fires_commission(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $referral->refresh();
        $this->assertSame(PipelineStage::CompletePayment, $referral->current_stage);

        // BR-4 — commission ledger row created for the agent, exactly once.
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id,
            'agent_id' => $agent->id,
            'amount_satang' => 26700, // 890000 * 3%
        ]);
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    public function test_confirming_before_finish_1st_doctor_meeting_is_rejected_and_creates_no_commission(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::WaitingAppointment);
        // AwaitingVerification, not Pending: since the 2026-08-21 audit an
        // order with no slip is refused before the stage is looked at, and
        // the STAGE gate is what this test exists to prove.
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertUnprocessable();

        $order->refresh();
        $this->assertSame(OrderStatus::AwaitingVerification, $order->status);
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    // -----------------------------------------------------------------
    // Admin uploads the slip FOR the customer (2026-08-21 audit follow-up)
    // -----------------------------------------------------------------

    public function test_an_admin_can_upload_the_slip_for_a_customer_who_paid_cash(): void
    {
        // WHY THIS EXISTS: requiring a slip before confirmation closed a
        // fraud path and, in the same stroke, stranded every customer who
        // pays at a branch or sends the slip over LINE — the public /pay
        // page was the only thing that could create one. Without this door
        // those orders are real payments that nobody can close.
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id]);
        $admin = $this->paymentConfirmer($company);

        $this->actingAs($admin)
            ->postJson("/api/v1/orders/{$order->id}/slip", [
                'slip' => UploadedFile::fake()->image('slip-from-line.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_verification');

        $order->refresh();
        Storage::disk('local')->assertExists($order->slip_path);

        // The record says STAFF put it there. Whoever confirms next needs
        // to know they are not looking at something the customer uploaded.
        $this->assertSame($admin->id, $order->slip_uploaded_by_user_id);

        // And the order can now be closed the ordinary way.
        $this->actingAs($admin)
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
    }

    public function test_a_customer_upload_leaves_no_staff_name_on_the_slip(): void
    {
        // NULL is a real answer here, not a missing one: the public path
        // must never look like staff supplied the proof of payment.
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id]);

        $this->postJson("/api/v1/pay/{$order->public_token}/slip", [
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertOk();

        $this->assertNull($order->fresh()->slip_uploaded_by_user_id);
    }

    public function test_an_agent_cannot_upload_a_slip_for_their_own_sale(): void
    {
        // The earner does not get to supply the proof either — that would
        // hand back the fraud path the audit closed, one step earlier.
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id]);

        $this->actingAs($agent)
            ->postJson("/api/v1/orders/{$order->id}/slip", [
                'slip' => UploadedFile::fake()->image('slip.jpg'),
            ])
            ->assertForbidden();

        $this->assertNull($order->fresh()->slip_path);
    }

    public function test_a_staff_slip_upload_is_audit_logged(): void
    {
        // The admin who uploads may also confirm, so this row is what makes
        // that sequence visible afterwards rather than merely allowed.
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id]);
        $admin = $this->paymentConfirmer($company);

        $this->actingAs($admin)
            ->postJson("/api/v1/orders/{$order->id}/slip", ['slip' => UploadedFile::fake()->image('slip.jpg')])
            ->assertOk();

        $log = AuditLog::where('action', 'order.slip_uploaded_by_staff')->sole();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($order->order_number, $log->new_values['order_number']);
    }

    public function test_an_already_paid_order_does_not_accept_another_slip(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->paid()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/slip", ['slip' => UploadedFile::fake()->image('slip.jpg')])
            ->assertUnprocessable();
    }

    public function test_admin_of_another_company_cannot_view_or_confirm_an_order(): void
    {
        $companyA = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $referral = $this->makeReferral($companyA, $agentA, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->create(['referral_id' => $referral->id]);

        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);

        // TenantScope hides company A's order from company B entirely (404).
        $this->actingAs($adminB)->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
        $this->actingAs($adminB)->postJson("/api/v1/orders/{$order->id}/confirm")->assertNotFound();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_slip_download_is_access_checked(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $owner = User::factory()->agent()->create(['company_id' => $company->id]);
        $otherAgent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $owner, PipelineStage::Finish1stDoctorMeeting);

        $path = "orders/slips/{$company->id}/slip.jpg";
        Storage::disk('local')->put($path, 'fake-slip-bytes');
        $order = Order::factory()->awaitingVerification()->create([
            'referral_id' => $referral->id,
            'slip_path' => $path,
        ]);

        // Other agent in the same company may see the order does not belong
        // to them → 403 (OrderPolicy::view), never the file.
        $this->actingAs($otherAgent)->getJson("/api/v1/orders/{$order->id}/slip")->assertForbidden();

        // Owning agent gets the file.
        $this->actingAs($owner)->get("/api/v1/orders/{$order->id}/slip")->assertOk();
    }
}
