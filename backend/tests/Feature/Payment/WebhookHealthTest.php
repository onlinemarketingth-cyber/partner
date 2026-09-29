<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentProvider;
use App\Models\Company;
use App\Models\CompanyPaymentGatewaySetting;
use App\Models\PaymentWebhookDeliveryStat;
use App\Models\PlatformPaymentGatewaySetting;
use App\Models\User;
use App\Notifications\WebhookSignatureRejectedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 2026-09-29 — the webhook warning and the "ตรวจสอบ webhook" button.
 *
 * Owner: "การตั้งค่า stripe ในบริษัทแยกกันมีปัญหาเรื่อง web hook ไม่ตรงกัน
 * สามารถขึ้นแจ้งเตือนมีปุ่มทดสอบ webhook ได้ไหม ถ้าได้ทำทั้ง stripe กับ omise",
 * warning on the screen AND to the Super Admins.
 */
class WebhookHealthTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_company_signing_secret';

    private const OMISE_SECRET = 'b21pc2VfdGVzdF9zaGFyZWRfc2VjcmV0';

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function stripeCompany(?\DateTimeInterface $verifiedAt = null): Company
    {
        $company = Company::factory()->create();
        CompanyPaymentGatewaySetting::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'provider' => PaymentProvider::Stripe->value,
            'credentials' => ['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => self::STRIPE_SECRET],
            'is_live' => false,
            'verified_at' => $verifiedAt ?? now(),
            'verified_note' => 'fixture',
        ]);
        $company->forceFill(['payment_provider' => PaymentProvider::Stripe->value])->save();

        return $company->refresh();
    }

    private function omiseCompany(): Company
    {
        $company = Company::factory()->create();
        CompanyPaymentGatewaySetting::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'provider' => PaymentProvider::Omise->value,
            'credentials' => ['public_key' => 'pkey_test_x', 'secret_key' => 'skey_test_x', 'webhook_secret' => self::OMISE_SECRET],
            'is_live' => false,
            'verified_at' => now(),
            'verified_note' => 'fixture',
        ]);

        return $company;
    }

    /** A Stripe event signed with $secret (Stripe's scheme: t=…,v1=HMAC("t.body")). */
    private function postStripe(string $target, string $secret): TestResponse
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1']]]);
        $t = time();

        return $this->call('POST', "/api/v1/webhooks/payments/stripe/{$target}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret),
        ], $body);
    }

    private function fakeStripeEndpoints(array $endpoints): void
    {
        Http::fake(['api.stripe.com/v1/webhook_endpoints*' => Http::response(['data' => $endpoints], 200)]);
    }

    private function allEvents(): array
    {
        return [
            'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed',
            'checkout.session.expired', 'payment_intent.payment_failed', 'charge.failed', 'charge.refunded',
        ];
    }

    // ── What arrived ─────────────────────────────────────────────────────

    public function test_a_refused_webhook_is_counted_and_shows_on_the_card_as_an_error(): void
    {
        Notification::fake();
        $company = $this->stripeCompany();

        $this->postStripe((string) $company->id, 'whsec_something_else')->assertStatus(401);

        $stripe = collect($this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways")->json('data.gateways'))
            ->firstWhere('provider', 'stripe');

        $this->assertSame(1, $stripe['webhook']['rejected_recent']);
        $this->assertSame('signature_rejected', $stripe['webhook']['problems'][0]['code']);
        $this->assertSame('error', $stripe['webhook']['problems'][0]['level']);
    }

    public function test_an_accepted_webhook_is_counted_and_the_card_is_clean(): void
    {
        $company = $this->stripeCompany();

        $this->postStripe((string) $company->id, self::STRIPE_SECRET)->assertOk();

        $stripe = collect($this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways")->json('data.gateways'))
            ->firstWhere('provider', 'stripe');

        $this->assertSame(1, $stripe['webhook']['accepted_recent']);
        $this->assertNotNull($stripe['webhook']['last_accepted_at']);
        $this->assertSame([], $stripe['webhook']['problems']);
    }

    public function test_the_first_refusal_of_the_day_emails_the_super_admins_once(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();
        $company = $this->stripeCompany();

        $this->postStripe((string) $company->id, 'whsec_wrong')->assertStatus(401);
        $this->postStripe((string) $company->id, 'whsec_wrong')->assertStatus(401);
        $this->postStripe((string) $company->id, 'whsec_wrong')->assertStatus(401);

        Notification::assertSentToTimes($admin, WebhookSignatureRejectedNotification::class, 1);
        $this->assertSame(3, PaymentWebhookDeliveryStat::sole()->rejected_count);
    }

    public function test_refusals_on_different_days_add_up_and_email_once_per_day(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();
        $company = $this->stripeCompany();

        $this->postStripe((string) $company->id, 'whsec_wrong');
        $this->travel(1)->days();
        $this->postStripe((string) $company->id, 'whsec_wrong');
        $this->postStripe((string) $company->id, 'whsec_wrong');

        Notification::assertSentToTimes($admin, WebhookSignatureRejectedNotification::class, 2);
        $this->assertSame(2, PaymentWebhookDeliveryStat::count());
        $this->actingAs($admin)->getJson("/api/v1/companies/{$company->id}/payment-gateways/stripe/webhook-check")
            ->assertJsonPath('data.deliveries.rejected_recent', 3);
    }

    public function test_a_gateway_that_has_heard_nothing_for_a_day_is_flagged(): void
    {
        $company = $this->stripeCompany(now()->subDays(2));

        $stripe = collect($this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways")->json('data.gateways'))
            ->firstWhere('provider', 'stripe');

        $this->assertSame('never_received', $stripe['webhook']['problems'][0]['code']);
        $this->assertSame('warning', $stripe['webhook']['problems'][0]['level']);
    }

    public function test_refusals_are_kept_per_account_so_one_company_never_warns_another(): void
    {
        Notification::fake();
        $a = $this->stripeCompany();
        $b = $this->stripeCompany();

        $this->postStripe((string) $a->id, 'whsec_wrong')->assertStatus(401);

        $stripeB = collect($this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$b->id}/payment-gateways")->json('data.gateways'))
            ->firstWhere('provider', 'stripe');
        $this->assertSame(0, $stripeB['webhook']['rejected_recent']);
    }

    // ── The button: Stripe's own dashboard ───────────────────────────────

    public function test_the_check_passes_when_stripe_has_our_url_enabled_with_every_event(): void
    {
        $company = $this->stripeCompany();
        $this->fakeStripeEndpoints([[
            'url' => "https://admin.example.test/backend/api/v1/webhooks/payments/stripe/{$company->id}",
            'status' => 'enabled',
            'enabled_events' => $this->allEvents(),
        ]]);

        $this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways/stripe/webhook-check")
            ->assertOk()
            ->assertJsonPath('data.setup_checkable', true)
            ->assertJsonPath('data.setup.checked', true)
            ->assertJsonPath('data.problems', [])
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_the_check_names_another_accounts_url_when_ours_is_missing(): void
    {
        $company = $this->stripeCompany();
        $this->fakeStripeEndpoints([[
            'url' => 'https://admin.example.test/backend/api/v1/webhooks/payments/stripe/999',
            'status' => 'enabled',
            'enabled_events' => ['*'],
        ]]);

        $response = $this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways/stripe/webhook-check")
            ->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.problems.0.code', 'endpoint_missing');

        $this->assertStringContainsString('/stripe/999', $response->json('data.problems.0.message'));
    }

    public function test_the_check_lists_the_events_stripe_is_not_sending(): void
    {
        $company = $this->stripeCompany();
        $this->fakeStripeEndpoints([[
            'url' => "https://x.test/api/v1/webhooks/payments/stripe/{$company->id}",
            'status' => 'enabled',
            'enabled_events' => ['checkout.session.completed'],
        ]]);

        $response = $this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways/stripe/webhook-check")
            ->assertJsonPath('data.problems.0.code', 'events_missing');

        $this->assertContains('charge.refunded', $response->json('data.setup.missing_events'));
        $this->assertNotContains('checkout.session.completed', $response->json('data.setup.missing_events'));
    }

    public function test_the_check_flags_a_disabled_endpoint(): void
    {
        $company = $this->stripeCompany();
        $this->fakeStripeEndpoints([[
            'url' => "https://x.test/api/v1/webhooks/payments/stripe/{$company->id}",
            'status' => 'disabled',
            'enabled_events' => ['*'],
        ]]);

        $this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways/stripe/webhook-check")
            ->assertJsonPath('data.problems.0.code', 'endpoint_disabled');
    }

    public function test_the_check_matches_company_12_exactly_not_company_1(): void
    {
        // A path-suffix match must not let /stripe/12 satisfy company 2.
        $company = $this->stripeCompany();
        $this->fakeStripeEndpoints([[
            'url' => "https://x.test/api/v1/webhooks/payments/stripe/1{$company->id}",
            'status' => 'enabled',
            'enabled_events' => ['*'],
        ]]);

        $this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways/stripe/webhook-check")
            ->assertJsonPath('data.problems.0.code', 'endpoint_missing');
    }

    public function test_a_stripe_it_cannot_reach_is_a_warning_not_a_crash(): void
    {
        $company = $this->stripeCompany();
        Http::fake(['api.stripe.com/*' => Http::response(['error' => []], 500)]);

        $this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways/stripe/webhook-check")
            ->assertOk()
            ->assertJsonPath('data.problems.0.code', 'setup_unreadable')
            ->assertJsonPath('data.status', 'warning');
    }

    // ── Omise: deliveries only ───────────────────────────────────────────

    public function test_omise_is_checked_on_what_arrived_and_says_its_dashboard_cannot_be_read(): void
    {
        Http::fake();
        $company = $this->omiseCompany();

        $this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways/omise/webhook-check")
            ->assertOk()
            ->assertJsonPath('data.setup_checkable', false)
            ->assertJsonPath('data.setup.checked', false);

        Http::assertNothingSent();
    }

    public function test_a_stripe_secret_pasted_into_the_omise_card_is_refused_on_save(): void
    {
        Http::fake(['api.omise.co/account' => Http::response(['email' => 'x@example.test'], 200)]);
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin())->putJson("/api/v1/companies/{$company->id}/payment-gateways/omise", [
            'credentials' => ['public_key' => 'pkey_test_x', 'secret_key' => 'skey_test_x', 'webhook_secret' => 'whsec_from_stripe'],
            'is_live' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('webhook_secret');

        Http::assertNothingSent();
    }

    // ── The platform's gateway, and who may ask ──────────────────────────

    public function test_the_platform_gateway_has_its_own_check_and_counters(): void
    {
        Notification::fake();
        PlatformPaymentGatewaySetting::create([
            'provider' => PaymentProvider::Stripe->value,
            'credentials' => ['publishable_key' => 'pk_test_p', 'secret_key' => 'sk_test_p', 'webhook_secret' => 'whsec_platform'],
            'is_live' => false,
            'verified_at' => now(),
            'verified_note' => 'fixture',
        ]);
        $this->fakeStripeEndpoints([[
            'url' => 'https://x.test/api/v1/webhooks/payments/stripe/platform',
            'status' => 'enabled',
            'enabled_events' => ['*'],
        ]]);

        $this->postStripe('platform', 'whsec_wrong')->assertStatus(401);

        $this->actingAs($this->superAdmin())->getJson('/api/v1/platform-payment-settings/gateways/stripe/webhook-check')
            ->assertOk()
            ->assertJsonPath('data.setup.endpoint_url', 'https://x.test/api/v1/webhooks/payments/stripe/platform')
            ->assertJsonPath('data.deliveries.rejected_recent', 1)
            ->assertJsonPath('data.problems.0.code', 'signature_rejected');
        $this->assertSame('platform', PaymentWebhookDeliveryStat::sole()->owner_key);
    }

    public function test_a_gateway_without_verified_keys_cannot_be_checked(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways/stripe/webhook-check")
            ->assertStatus(422);
    }

    public function test_only_a_super_admin_may_run_the_check(): void
    {
        $company = $this->stripeCompany();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson("/api/v1/companies/{$company->id}/payment-gateways/stripe/webhook-check")->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/platform-payment-settings/gateways/stripe/webhook-check')->assertForbidden();
    }

    public function test_the_check_never_returns_a_secret(): void
    {
        $company = $this->stripeCompany();
        $this->fakeStripeEndpoints([]);

        $body = $this->actingAs($this->superAdmin())->getJson("/api/v1/companies/{$company->id}/payment-gateways/stripe/webhook-check")->getContent();

        $this->assertStringNotContainsString(self::STRIPE_SECRET, $body);
        $this->assertStringNotContainsString('sk_test_x', $body);
    }
}
