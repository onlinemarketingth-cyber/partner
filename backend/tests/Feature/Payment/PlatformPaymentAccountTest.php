<?php

namespace Tests\Feature\Payment;

use App\Enums\CommissionRateType;
use App\Enums\OrderStatus;
use App\Enums\PaymentAccountScope;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PipelineStage;
use App\Models\AuditLog;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\CompanyPaymentGatewaySetting;
use App\Models\Order;
use App\Models\PlatformPaymentGatewaySetting;
use App\Models\PlatformPaymentSetting;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ADR-050 — "ระบบชำระเงินใช้ค่าเดียวทุกบริษัท หรือแยกบริษัท", a Super Admin switch.
 *
 * Owner, 2026-09-29: in "ใช้ค่าเดียวทุกบริษัท" every channel (transfer,
 * PromptPay, card) is the platform's, for every company, no exceptions; the
 * system follows whatever the Super Admin has set; an order keeps the
 * accounts it was created with.
 *
 * The money-critical cases are the webhook ones: a platform-signed event
 * must only ever confirm a platform-account order, and a company's endpoint
 * must never confirm one either.
 */
class PlatformPaymentAccountTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_SECRET = 'whsec_company_secret';

    private const PLATFORM_SECRET = 'whsec_platform_secret';

    private const SETTINGS = '/api/v1/platform-payment-settings';

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function companyWithOwnAccount(): Company
    {
        $company = Company::factory()->create([
            'payment_bank_name' => 'Company Bank',
            'payment_bank_account_number' => '111-1-11111-1',
            'payment_bank_account_name' => 'Company Co.',
            'payment_promptpay_id' => '0811111111',
        ]);

        CompanyPaymentGatewaySetting::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'provider' => PaymentProvider::Omise->value,
            'credentials' => ['public_key' => 'pkey_test_company', 'secret_key' => 'skey_test_company', 'webhook_secret' => self::COMPANY_SECRET],
            'is_live' => false,
            'verified_at' => now(),
            'verified_note' => 'fixture',
        ]);
        $company->forceFill(['payment_provider' => PaymentProvider::Omise->value])->save();

        return $company->refresh();
    }

    /** The platform, fully set up: a transfer destination and an active, verified Omise. */
    private function readyPlatform(): PlatformPaymentSetting
    {
        $settings = PlatformPaymentSetting::current();
        $settings->fill([
            'bank_name' => 'Platform Bank',
            'bank_account_number' => '999-9-99999-9',
            'bank_account_name' => 'Platform Ltd.',
            'promptpay_id' => '0899999999',
        ])->save();

        PlatformPaymentGatewaySetting::create([
            'provider' => PaymentProvider::Omise->value,
            'credentials' => ['public_key' => 'pkey_test_platform', 'secret_key' => 'skey_test_platform', 'webhook_secret' => self::PLATFORM_SECRET],
            'is_live' => false,
            'verified_at' => now(),
            'verified_note' => 'fixture',
        ]);
        $settings->forceFill(['payment_provider' => PaymentProvider::Omise->value])->save();

        return $settings->refresh();
    }

    private function switchTo(PaymentAccountScope $mode): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson(self::SETTINGS.'/mode', ['mode' => $mode->value])
            ->assertOk();
    }

    /** A real order through OrderService, so the stamp is the one production writes. */
    private function newOrder(Company $company): Order
    {
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1_000_000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 500,
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

        return app(OrderService::class)->createForReferral($referral, PaymentMethod::BankTransfer);
    }

    private function postWebhook(string $target, string $body, string $secret): TestResponse
    {
        return $this->call('POST', "/api/v1/webhooks/payments/omise/{$target}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_OMISE_SIGNATURE' => hash_hmac('sha256', $body, $secret),
        ], $body);
    }

    private function chargeComplete(Order $order, string $chargeId = 'chrg_test_1'): string
    {
        return json_encode(['key' => 'charge.complete', 'data' => [
            'id' => $chargeId,
            'status' => 'successful',
            'amount' => $order->amount_satang,
            'currency' => 'THB',
            'metadata' => ['order_token' => $order->public_token],
        ]], JSON_UNESCAPED_SLASHES);
    }

    // ── The default ──────────────────────────────────────────────────────

    public function test_with_nothing_set_every_order_pays_its_own_company_as_before(): void
    {
        $company = $this->companyWithOwnAccount();
        $order = $this->newOrder($company);

        $this->assertSame(PaymentAccountScope::Company, $order->payment_account);
        $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('data.company_payment.bank_account_number', '111-1-11111-1')
            ->assertJsonPath('data.gateway.online.provider', 'omise');
        $this->actingAs($this->superAdmin())->getJson(self::SETTINGS)->assertJsonPath('data.mode', 'company');
    }

    // ── Switching ────────────────────────────────────────────────────────

    public function test_switching_to_the_platform_is_refused_until_it_can_take_money_both_ways(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson(self::SETTINGS.'/mode', ['mode' => 'platform'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
        $this->assertSame(PaymentAccountScope::Company, PlatformPaymentSetting::current()->mode);

        // A transfer destination alone is not enough: no company could take a card.
        $this->actingAs($admin)->putJson(self::SETTINGS.'/transfer-account', [
            'promptpay_id' => null, 'bank_name' => 'B', 'bank_account_number' => '123', 'bank_account_name' => 'N',
        ])->assertOk()->assertJsonCount(1, 'data.platform_ready_problems');
        $this->actingAs($admin)->putJson(self::SETTINGS.'/mode', ['mode' => 'platform'])->assertStatus(422);
    }

    public function test_once_ready_the_switch_is_audited_and_new_orders_pay_the_platform(): void
    {
        $company = $this->companyWithOwnAccount();
        $this->readyPlatform();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson(self::SETTINGS.'/mode', ['mode' => 'platform'])
            ->assertOk()
            ->assertJsonPath('data.mode', 'platform')
            ->assertJsonPath('data.platform_ready_problems', []);

        $log = AuditLog::where('action', 'platform_payment.mode_changed')->sole();
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame(['mode' => 'company'], $log->old_values);
        $this->assertSame(['mode' => 'platform'], $log->new_values);

        $order = $this->newOrder($company);
        $this->assertSame(PaymentAccountScope::Platform, $order->payment_account);

        $page = $this->getJson("/api/v1/pay/{$order->public_token}")->assertOk();
        $page->assertJsonPath('data.company_payment.bank_account_number', '999-9-99999-9')
            ->assertJsonPath('data.company_payment.promptpay_id', '0899999999')
            ->assertJsonPath('data.gateway.online.provider', 'omise');
        // The QR is built from the PLATFORM's PromptPay, not the company's.
        $this->assertStringContainsString('66899999999', $page->json('data.promptpay_payload'));
    }

    public function test_a_company_with_no_promptpay_of_its_own_still_gets_the_platforms_qr(): void
    {
        $company = $this->companyWithOwnAccount();
        $company->forceFill(['payment_promptpay_id' => null])->save();
        $this->readyPlatform();
        $this->switchTo(PaymentAccountScope::Platform);
        $order = $this->newOrder($company);

        $payload = $this->getJson("/api/v1/pay/{$order->public_token}")->json('data.promptpay_payload');

        $this->assertNotSame('', $payload);
        $this->assertStringContainsString('66899999999', $payload);
    }

    public function test_an_order_created_before_the_switch_keeps_the_accounts_it_showed(): void
    {
        $company = $this->companyWithOwnAccount();
        $before = $this->newOrder($company);
        $this->readyPlatform();
        $this->switchTo(PaymentAccountScope::Platform);

        $this->getJson("/api/v1/pay/{$before->public_token}")
            ->assertJsonPath('data.company_payment.bank_account_number', '111-1-11111-1');
        $this->assertSame(PaymentAccountScope::Company, $before->fresh()->payment_account);
    }

    public function test_a_platform_order_is_charged_with_the_platforms_key(): void
    {
        $company = $this->companyWithOwnAccount();
        $this->readyPlatform();
        $this->switchTo(PaymentAccountScope::Platform);
        $order = $this->newOrder($company);
        Http::fake(['api.omise.co/charges' => Http::response(['id' => 'chrg_test_p', 'status' => 'successful', 'amount' => $order->amount_satang, 'currency' => 'THB'], 200)]);

        $this->postJson("/api/v1/pay/{$order->public_token}/charge", ['payment_token' => 'tokn_test_abc'])->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.omise.co/charges'
            && str_contains($request->header('Authorization')[0] ?? '', base64_encode('skey_test_platform:')));
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    // ── Webhooks ─────────────────────────────────────────────────────────

    public function test_the_platform_webhook_confirms_a_platform_order_of_any_company(): void
    {
        $company = $this->companyWithOwnAccount();
        $this->readyPlatform();
        $this->switchTo(PaymentAccountScope::Platform);
        $order = $this->newOrder($company);

        $this->postWebhook('platform', $this->chargeComplete($order), self::PLATFORM_SECRET)
            ->assertOk()
            ->assertJsonPath('message', 'ok');

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertDatabaseHas('payment_webhook_events', ['order_id' => $order->id, 'company_id' => $company->id]);
    }

    public function test_the_platform_webhook_never_confirms_a_companys_own_account_order(): void
    {
        $company = $this->companyWithOwnAccount();
        $order = $this->newOrder($company);
        $this->readyPlatform();

        $this->postWebhook('platform', $this->chargeComplete($order), self::PLATFORM_SECRET)
            ->assertOk()
            ->assertJsonPath('message', 'unmatched');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertDatabaseHas('payment_webhook_events', ['company_id' => null, 'result' => 'unmatched']);
        $this->assertSame(1, AuditLog::where('action', 'order.gateway_payment_unmatched')->whereNull('company_id')->count());
    }

    public function test_a_companys_webhook_never_confirms_a_platform_order(): void
    {
        $company = $this->companyWithOwnAccount();
        $this->readyPlatform();
        $this->switchTo(PaymentAccountScope::Platform);
        $order = $this->newOrder($company);

        $this->postWebhook((string) $company->id, $this->chargeComplete($order), self::COMPANY_SECRET)
            ->assertOk()
            ->assertJsonPath('message', 'unmatched');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_a_platform_webhook_signed_with_any_other_key_is_refused(): void
    {
        $company = $this->companyWithOwnAccount();
        $this->readyPlatform();
        $this->switchTo(PaymentAccountScope::Platform);
        $order = $this->newOrder($company);

        $this->postWebhook('platform', $this->chargeComplete($order), self::COMPANY_SECRET)->assertStatus(401);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_an_uninteresting_platform_event_is_acknowledged_and_kept_under_no_company(): void
    {
        $this->readyPlatform();
        $body = json_encode(['key' => 'customer.create', 'data' => ['id' => 'cust_1']]);

        $this->postWebhook('platform', $body, self::PLATFORM_SECRET)->assertOk()->assertJsonPath('message', 'ignored');

        $this->assertDatabaseHas('payment_webhook_events', ['company_id' => null, 'order_id' => null]);
    }

    public function test_the_platform_webhook_does_not_exist_until_the_platform_has_keys(): void
    {
        $this->postWebhook('platform', '{}', self::PLATFORM_SECRET)->assertNotFound();
    }

    // ── Company settings while every company uses the platform ───────────

    public function test_a_companys_own_settings_are_frozen_but_kept_while_the_platform_is_used(): void
    {
        $company = $this->companyWithOwnAccount();
        $this->readyPlatform();
        $this->switchTo(PaymentAccountScope::Platform);
        $admin = $this->superAdmin();
        $base = "/api/v1/companies/{$company->id}/payment-gateways";

        $this->actingAs($admin)->getJson($base)->assertOk()->assertJsonPath('data.account_mode', 'platform');
        $this->actingAs($admin)->postJson("{$base}/deactivate")->assertStatus(422)->assertJsonValidationErrors('mode');
        $this->actingAs($admin)->postJson("{$base}/activate", ['provider' => 'omise'])->assertStatus(422);
        $this->actingAs($admin)->putJson("{$base}/omise", ['credentials' => [], 'is_live' => false])->assertStatus(422);

        $this->assertSame('omise', $company->fresh()->payment_provider);
        $this->assertSame(1, CompanyPaymentGatewaySetting::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_the_platform_transfer_account_cannot_be_emptied_while_every_company_uses_it(): void
    {
        $this->readyPlatform();
        $this->switchTo(PaymentAccountScope::Platform);

        $this->actingAs($this->superAdmin())->putJson(self::SETTINGS.'/transfer-account', [
            'promptpay_id' => null, 'bank_name' => null, 'bank_account_number' => '', 'bank_account_name' => null,
        ])->assertStatus(422);

        $this->assertSame('999-9-99999-9', PlatformPaymentSetting::current()->bank_account_number);
    }

    // ── The platform's own gateway ───────────────────────────────────────

    public function test_platform_keys_are_verified_before_they_are_stored_and_never_returned(): void
    {
        Http::fake(['api.omise.co/account' => Http::response(['email' => 'platform@example.test'], 200)]);
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->putJson(self::SETTINGS.'/gateways/omise', [
            'credentials' => ['public_key' => 'pkey_test_new', 'secret_key' => 'skey_test_new', 'webhook_secret' => 'whsec_new'],
            'is_live' => false,
        ])->assertOk();

        $this->assertStringNotContainsString('skey_test_new', $response->getContent());
        $this->assertStringNotContainsString('whsec_new', $response->getContent());
        $this->assertTrue(PlatformPaymentGatewaySetting::where('provider', 'omise')->sole()->isVerified());
        $this->assertSame(1, AuditLog::where('action', 'platform_payment.gateway_saved')->count());

        $this->actingAs($admin)->postJson(self::SETTINGS.'/gateways/activate', ['provider' => 'omise'])
            ->assertOk()
            ->assertJsonPath('data.active_provider', 'omise');
    }

    public function test_rejected_platform_keys_are_not_stored(): void
    {
        Http::fake(['api.omise.co/account' => Http::response(['code' => 'authentication_failure'], 401)]);

        $this->actingAs($this->superAdmin())->putJson(self::SETTINGS.'/gateways/omise', [
            'credentials' => ['public_key' => 'pkey_test_x', 'secret_key' => 'skey_test_x', 'webhook_secret' => 'whsec_x'],
            'is_live' => false,
        ])->assertStatus(422);

        $this->assertSame(0, PlatformPaymentGatewaySetting::count());
    }

    public function test_an_unverified_platform_gateway_cannot_be_switched_on(): void
    {
        $this->actingAs($this->superAdmin())->postJson(self::SETTINGS.'/gateways/activate', ['provider' => 'omise'])
            ->assertStatus(422);
    }

    // ── Who may touch any of this ────────────────────────────────────────

    public function test_only_a_super_admin_may_read_or_change_the_platform_settings(): void
    {
        $company = Company::factory()->create();
        foreach ([User::factory()->companyAdmin()->create(['company_id' => $company->id]), User::factory()->agent()->create(['company_id' => $company->id])] as $user) {
            $this->actingAs($user)->getJson(self::SETTINGS)->assertForbidden();
            $this->actingAs($user)->putJson(self::SETTINGS.'/mode', ['mode' => 'company'])->assertForbidden();
            $this->actingAs($user)->putJson(self::SETTINGS.'/transfer-account', [
                'promptpay_id' => null, 'bank_name' => null, 'bank_account_number' => '1', 'bank_account_name' => null,
            ])->assertForbidden();
            $this->actingAs($user)->postJson(self::SETTINGS.'/gateways/deactivate')->assertForbidden();
        }

        $this->assertSame(0, PlatformPaymentSetting::count());
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())->putJson(self::SETTINGS.'/mode', ['mode' => 'shared'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }
}
