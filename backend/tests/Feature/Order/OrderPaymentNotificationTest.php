<?php

namespace Tests\Feature\Order;

use App\Enums\CommissionRateType;
use App\Enums\NotificationType;
use App\Enums\PipelineStage;
use App\Mail\OrderPaymentConfirmedMail;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PlatformMailSetting;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Platform\PlatformMailSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// TASK-190 §4/§6 — the two notifications fired from
// OrderService::confirmPayment() / OrderController::confirm(): the
// in-app agent Notification (unconditional, guarded by the same
// !$alreadyClosed as TASK-189's voucher) and the customer email
// (conditional on is_enabled + client.email, sent after the transaction
// commits, never allowed to affect the HTTP response).
class OrderPaymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);

        return $tier;
    }

    /** A certified agent + a referral at $stage, whose client has the given email. */
    private function makeReferral(Company $company, User $agent, PipelineStage $stage, ?string $clientEmail = 'customer@example.com'): Referral
    {
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $agent->id,
            'email' => $clientEmail,
        ]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
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

    /** Enable platform mail and make sure the shared cache key sees it immediately. */
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
        // AppServiceProvider::boot() already cached "no row" earlier in this
        // same test's app boot (before this insert), and
        // PlatformMailSettingController/Controller reads through the SAME
        // cache key (PlatformMailSettingService::CACHE_KEY) — forget it so
        // the row created above is what get() sees, exactly like
        // PlatformMailSettingService::update() does on the real write path.
        Cache::forget(PlatformMailSettingService::CACHE_KEY);
    }

    // -----------------------------------------------------------------
    // In-app agent notification — idempotent under a double-confirm
    // -----------------------------------------------------------------

    /*
     * SECURITY AUDIT 2026-08-21 — the confirming actor in this file is a
     * Company Admin, not the agent, because an agent may no longer confirm
     * a payment they earn from (human ruling D1). The AGENT is still the
     * one who must be notified, which is what these tests are about — so
     * the recipient assertions below deliberately still name $agent.
     */

    /**
     * MUTATION-CHECK STYLE PROOF (spec §6, mirroring TASK-189's
     * voucher-issued-exactly-once test): force a double-confirm and prove
     * exactly ONE OrderPaymentConfirmed notification exists for the agent
     * — the `! $alreadyClosed` guard around the notify() call in
     * OrderService::confirmPayment() is the thing under test.
     */
    public function test_agent_is_notified_exactly_once_even_under_a_double_confirm(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();
        // Second confirm: top-of-method idempotency check short-circuits —
        // asserting the OUTCOME (exactly one notification), not the code
        // path, per the same reasoning VoucherTest's own mutation test uses.
        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();

        $this->assertSame(
            1,
            Notification::where('user_id', $agent->id)
                ->where('type', NotificationType::OrderPaymentConfirmed)
                ->count(),
        );
    }

    public function test_agent_notification_carries_the_order_number_and_link(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();

        $notification = Notification::where('user_id', $agent->id)
            ->where('type', NotificationType::OrderPaymentConfirmed)
            ->firstOrFail();

        $this->assertStringContainsString($order->order_number, (string) $notification->body);
        $this->assertSame('/orders', $notification->link);
        $this->assertSame($order->id, $notification->data['order_id']);
        $this->assertSame($company->id, $notification->company_id);
    }

    // -----------------------------------------------------------------
    // Customer email — skip conditions
    // -----------------------------------------------------------------

    public function test_mail_is_not_attempted_when_platform_mail_is_disabled(): void
    {
        Mail::fake();

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        // No PlatformMailSetting row at all — default state is disabled
        // (fail closed, §3.1).
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, 'customer@example.com');
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();

        Mail::assertNothingSent();
    }

    public function test_mail_is_not_attempted_when_client_has_no_email(): void
    {
        Mail::fake();
        $this->enablePlatformMail();

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, null);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();

        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Customer email — the happy path
    // -----------------------------------------------------------------

    public function test_mail_is_sent_to_the_client_when_enabled_and_email_present(): void
    {
        Mail::fake();
        $this->enablePlatformMail();

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, 'customer@example.com');
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) use ($order) {
            return $mail->order->id === $order->id
                && $mail->hasTo('customer@example.com');
        });
    }

    // -----------------------------------------------------------------
    // A mail-send exception must never fail/rollback the confirmation
    // -----------------------------------------------------------------

    public function test_a_mail_send_exception_does_not_fail_or_roll_back_the_payment_confirmation(): void
    {
        $this->enablePlatformMail();

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, 'customer@example.com');
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        // Simulate the real Mailer throwing (e.g. SMTP connection refused)
        // WITHOUT depending on real network access in the test environment
        // — Mail::shouldReceive() swaps in a Mockery spy on the Mail
        // facade, the same technique Laravel's own docs use for asserting
        // failure-handling around mail. The controller's try/catch is what
        // is under test here, not the transport itself.
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $response = $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm");

        $response->assertOk();
        $this->assertSame('paid', $order->fresh()->status->value);
        // The in-app notification (the guaranteed path per §4.3) still
        // fired — a mail failure must not touch anything upstream of it.
        $this->assertSame(
            1,
            Notification::where('user_id', $agent->id)
                ->where('type', NotificationType::OrderPaymentConfirmed)
                ->count(),
        );
    }
}
