<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentProvider;
use App\Models\Company;
use App\Models\CompanyPaymentGatewaySetting;
use App\Models\Order;
use App\Models\User;
use App\Services\Payment\Gateways\OmiseGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Which gateway takes a company's money, and who may say so.
 *
 * ── WHY THIS FILE IS THE MOST IMPORTANT ONE IN THE PAYMENT WORK ──
 *
 * ADR-027 §3 records that an earlier draft put one OMISE_SECRET_KEY in .env
 * for the whole platform. That would have routed every tenant's customer
 * revenue into whichever account the platform happened to configure — real
 * money, into the wrong company's bank account, with nothing on any screen
 * looking wrong. These cases exist so that class of mistake cannot be made
 * again quietly.
 *
 * ── WHAT BREAKS SILENTLY ──
 *
 * 1. A SECRET KEY COMES BACK OUT OF THE API. A settings screen that can read
 *    a secret back is a screen that can leak one, and the leak looks like a
 *    normal 200. The rule (PlatformMailSettingService's, applied here) is
 *    that a secret is reported as SET or NOT SET and never as a value.
 *
 * 2. A COMPANY ADMIN EDITS IT. They could redirect their own company's
 *    income, and it would look like an ordinary settings edit everywhere.
 *
 * 3. TEST AND LIVE KEYS GET CROSSED. A test key in live mode takes payments
 *    that never settle — discovered weeks later in a bank statement. A live
 *    key in test mode charges real customers during testing, which is worse.
 *
 * 4. AN UNVERIFIED GATEWAY GOES LIVE. Wrong keys fail on the CUSTOMER's
 *    screen, one payment at a time. Nobody here finds out.
 *
 * 5. TWO GATEWAYS END UP ACTIVE. The human's rule is exactly one. Enforced
 *    by shape — a single column on `companies` — so these cases check the
 *    shape holds rather than that some code remembered to check.
 */
class CompanyPaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const OMISE_PATH = '/api/v1/companies/%d/payment-gateways';

    /** @return array{0: Company, 1: User} */
    private function companyAndSuperAdmin(): array
    {
        return [Company::factory()->create(), User::factory()->superAdmin()->create()];
    }

    /** Omise's /account, answered without touching the network. */
    private function fakeOmiseOk(string $email = 'finance@thailife.example'): void
    {
        Http::fake(['api.omise.co/account' => Http::response(['email' => $email], 200)]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function omiseKeys(array $overrides = []): array
    {
        return [
            'public_key' => 'pkey_test_abc123',
            'secret_key' => 'skey_test_abc123',
            'webhook_secret' => 'whsec_abc123',
            ...$overrides,
        ];
    }

    // ── Who may touch this ───────────────────────────────────────────────

    public function test_a_company_admin_cannot_read_or_change_the_gateway(): void
    {
        // They could point their own company's revenue somewhere else, and it
        // would look like any other settings edit on every screen.
        [$company] = $this->companyAndSuperAdmin();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $path = sprintf(self::OMISE_PATH, $company->id);

        $this->actingAs($admin)->getJson($path)->assertForbidden();
        $this->actingAs($admin)->putJson($path.'/omise', [
            'credentials' => $this->omiseKeys(),
            'is_live' => false,
        ])->assertForbidden();
        $this->actingAs($admin)->postJson($path.'/activate', ['provider' => 'omise'])->assertForbidden();
    }

    public function test_an_agent_cannot_either(): void
    {
        [$company] = $this->companyAndSuperAdmin();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson(sprintf(self::OMISE_PATH, $company->id))->assertForbidden();
    }

    // ── Secrets never come back ──────────────────────────────────────────

    public function test_a_secret_key_is_never_returned_by_the_api(): void
    {
        // THE ONE THAT MATTERS MOST. Not even a masked fragment: a key's last
        // four characters identify nothing an admin can act on, and every
        // character published is one fewer for an attacker to guess.
        $this->fakeOmiseOk();
        [$company, $superAdmin] = $this->companyAndSuperAdmin();
        $path = sprintf(self::OMISE_PATH, $company->id);

        $this->actingAs($superAdmin)->putJson($path.'/omise', [
            'credentials' => $this->omiseKeys(),
            'is_live' => false,
        ])->assertOk();

        $body = $this->actingAs($superAdmin)->getJson($path)->assertOk()->getContent();

        $this->assertStringNotContainsString('skey_test_abc123', $body);
        $this->assertStringNotContainsString('whsec_abc123', $body);
        // The PUBLIC key does come back — it is in the pay page's HTML for
        // every customer to read, and hiding it from the admin who has to
        // check it is right would be theatre.
        $this->assertStringContainsString('pkey_test_abc123', $body);
    }

    public function test_the_screen_still_learns_whether_a_secret_is_set(): void
    {
        $this->fakeOmiseOk();
        [$company, $superAdmin] = $this->companyAndSuperAdmin();
        $path = sprintf(self::OMISE_PATH, $company->id);

        $this->actingAs($superAdmin)->putJson($path.'/omise', [
            'credentials' => $this->omiseKeys(),
            'is_live' => false,
        ])->assertOk();

        $gateways = $this->actingAs($superAdmin)->getJson($path)->json('data.gateways');
        $omise = collect($gateways)->firstWhere('provider', 'omise');
        $secretField = collect($omise['fields'])->firstWhere('key', 'secret_key');

        $this->assertTrue($secretField['is_set']);
        $this->assertNull($secretField['value']);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        // The column is read raw: an `encrypted` cast that was silently
        // dropped would still round-trip through the model and look fine.
        $this->fakeOmiseOk();
        [$company, $superAdmin] = $this->companyAndSuperAdmin();

        $this->actingAs($superAdmin)->putJson(sprintf(self::OMISE_PATH, $company->id).'/omise', [
            'credentials' => $this->omiseKeys(),
            'is_live' => false,
        ])->assertOk();

        $raw = \DB::table('company_payment_gateway_settings')->value('credentials');

        $this->assertStringNotContainsString('skey_test_abc123', (string) $raw);
    }

    // ── Test / live keys must not cross ──────────────────────────────────

    public function test_a_test_key_is_refused_in_live_mode(): void
    {
        // Payments would look successful and never settle — a failure whose
        // symptom appears weeks later in a bank statement.
        $this->fakeOmiseOk();
        [$company, $superAdmin] = $this->companyAndSuperAdmin();

        $this->actingAs($superAdmin)
            ->putJson(sprintf(self::OMISE_PATH, $company->id).'/omise', [
                'credentials' => $this->omiseKeys(),
                'is_live' => true,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('company_payment_gateway_settings', 0);
    }

    public function test_a_live_key_is_refused_in_test_mode(): void
    {
        // Worse than the other direction: it charges real customers real
        // money while somebody believes they are testing.
        $this->fakeOmiseOk();
        [$company, $superAdmin] = $this->companyAndSuperAdmin();

        $this->actingAs($superAdmin)
            ->putJson(sprintf(self::OMISE_PATH, $company->id).'/omise', [
                'credentials' => $this->omiseKeys([
                    'public_key' => 'pkey_live_abc',
                    'secret_key' => 'skey_live_abc',
                ]),
                'is_live' => false,
            ])
            ->assertStatus(422);
    }

    public function test_credentials_omise_rejects_are_not_saved(): void
    {
        // A failed row sitting in the table looks configured, and the next
        // person to open the screen sees a filled-in form.
        Http::fake(['api.omise.co/account' => Http::response(['code' => 'authentication_failure'], 401)]);
        [$company, $superAdmin] = $this->companyAndSuperAdmin();

        $this->actingAs($superAdmin)
            ->putJson(sprintf(self::OMISE_PATH, $company->id).'/omise', [
                'credentials' => $this->omiseKeys(),
                'is_live' => false,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('company_payment_gateway_settings', 0);
    }

    // ── Exactly one active gateway ───────────────────────────────────────

    public function test_a_company_starts_with_no_online_gateway_but_can_still_take_money(): void
    {
        /*
         * 2026-09-03 — THE COLUMN CHANGED MEANING, SO THIS TEST DID TOO.
         *
         * `payment_provider` used to answer "how does this company get paid",
         * and 'manual' was the honest answer. It now answers only "which
         * ONLINE gateway is switched on", because bank transfer / PromptPay
         * is always available and is not a setting anyone chooses. The honest
         * answer for a company that has switched none on is NULL.
         *
         * The second assertion is the one that matters to a customer: no
         * online gateway does not mean no way to pay.
         */
        [$company] = $this->companyAndSuperAdmin();

        $this->assertNull($company->payment_provider);
        $this->assertTrue(PaymentProvider::Manual->requiresHumanVerification());
    }

    public function test_activating_a_gateway_replaces_the_previous_one(): void
    {
        // One column, so two active providers cannot be written down at all.
        $this->fakeOmiseOk();
        [$company, $superAdmin] = $this->companyAndSuperAdmin();
        $path = sprintf(self::OMISE_PATH, $company->id);

        $this->actingAs($superAdmin)->putJson($path.'/omise', [
            'credentials' => $this->omiseKeys(), 'is_live' => false,
        ])->assertOk();

        $this->actingAs($superAdmin)->postJson($path.'/activate', ['provider' => 'omise'])->assertOk();

        $this->assertSame('omise', $company->refresh()->payment_provider);
        $this->assertSame(1, CompanyPaymentGatewaySetting::withoutGlobalScopes()->count());
    }

    public function test_an_unverified_gateway_cannot_be_activated(): void
    {
        // Wrong keys fail on the CUSTOMER's screen, one payment at a time.
        // Nobody on this side finds out.
        [$company, $superAdmin] = $this->companyAndSuperAdmin();

        CompanyPaymentGatewaySetting::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'provider' => PaymentProvider::Omise->value,
            'credentials' => ['secret_key' => 'skey_test_x'],
            'is_live' => false,
            'verified_at' => null,
        ]);

        $this->actingAs($superAdmin)
            ->postJson(sprintf(self::OMISE_PATH, $company->id).'/activate', ['provider' => 'omise'])
            ->assertStatus(422);

        // Nothing was switched on, so nothing is active — see
        // test_a_company_starts_with_no_online_gateway_but_can_still_take_money.
        $this->assertNull($company->refresh()->payment_provider);
    }

    public function test_a_gateway_that_was_never_configured_cannot_be_activated(): void
    {
        [$company, $superAdmin] = $this->companyAndSuperAdmin();

        $this->actingAs($superAdmin)
            ->postJson(sprintf(self::OMISE_PATH, $company->id).'/activate', ['provider' => 'omise'])
            ->assertStatus(422);
    }

    public function test_an_unknown_provider_is_a_404_not_a_fallback(): void
    {
        // Defaulting to Manual here would let a typo in a URL quietly
        // reconfigure a company onto slip uploads.
        [$company, $superAdmin] = $this->companyAndSuperAdmin();

        $this->actingAs($superAdmin)
            ->putJson(sprintf(self::OMISE_PATH, $company->id).'/nonsense', [
                'credentials' => [], 'is_live' => false,
            ])
            ->assertNotFound();
    }

    // ── Per company, never platform-wide ─────────────────────────────────

    public function test_one_companys_credentials_are_not_another_companys(): void
    {
        /*
         * THE ADR-027 §3 BUG, made impossible. The rejected draft used one
         * .env key pair for the platform; this asserts the storage is per
         * company and that reading one tenant's setup cannot return another's.
         */
        $this->fakeOmiseOk('a@example.com');
        [$companyA, $superAdmin] = $this->companyAndSuperAdmin();
        $companyB = Company::factory()->create();

        $this->actingAs($superAdmin)->putJson(sprintf(self::OMISE_PATH, $companyA->id).'/omise', [
            'credentials' => $this->omiseKeys(['public_key' => 'pkey_test_AAA']),
            'is_live' => false,
        ])->assertOk();

        $bodyB = $this->actingAs($superAdmin)->getJson(sprintf(self::OMISE_PATH, $companyB->id))->assertOk();

        $this->assertStringNotContainsString('pkey_test_AAA', $bodyB->getContent());
        // null, not 'manual': company B switched no online gateway on.
        $this->assertNull($bodyB->json('data.active_provider'));
    }

    public function test_the_verification_note_names_the_account_that_answered(): void
    {
        /*
         * A green tick cannot tell an admin they connected the WRONG
         * company's Omise. On a platform where each tenant's revenue lands in
         * their own account, that is the mistake worth catching, and seeing
         * an unexpected email is what catches it.
         */
        $this->fakeOmiseOk('someone-elses@company.example');
        [$company, $superAdmin] = $this->companyAndSuperAdmin();

        $message = $this->actingAs($superAdmin)
            ->putJson(sprintf(self::OMISE_PATH, $company->id).'/omise', [
                'credentials' => $this->omiseKeys(), 'is_live' => false,
            ])
            ->assertOk()
            ->json('message');

        $this->assertStringContainsString('someone-elses@company.example', $message);
    }

    // ── Money arithmetic ─────────────────────────────────────────────────

    public function test_omise_amounts_are_satang_with_no_conversion(): void
    {
        /*
         * Omise counts in the currency's smallest unit — satang for THB —
         * which is what BR-3 already stores. The absence of a ×100 anywhere
         * is the correctness argument, and it is easier to verify than the
         * correctness of a conversion would be.
         *
         * A ×100 error on a payment gateway is the most expensive bug
         * available in this system, in either direction.
         */
        $order = new Order(['amount_satang' => 890000]);
        $intent = (new OmiseGateway)->startPayment($order, ['public_key' => 'pkey_test_x'], false);

        $this->assertSame(890000, $intent->amountSatang);
    }
}
