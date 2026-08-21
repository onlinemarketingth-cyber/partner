<?php

namespace Tests\Feature\Order;

use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderVoucher;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Models\VoucherRedemption;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// ADR-033 (TASK-189) — post-payment voucher issuance/redemption + shipping
// capture. Covers: a paid order always minting exactly one voucher (even
// under a double-confirm race), quota/expiry refusal, cross-tenant
// redemption (404, §5 rule 5), Ability::VoucherRedeem excluding Agent, and
// shipping fields required server-side only when product.requires_shipping.
class VoucherTest extends TestCase
{
    use RefreshDatabase;

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);

        return $tier;
    }

    /** A certified agent + a referral at $stage, for a product with the given voucher/shipping config. */
    private function makeReferral(Company $company, User $agent, PipelineStage $stage, array $productAttrs = []): Referral
    {
        $tier = $this->passBasicCert($agent, $company);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(array_merge([
            'company_id' => $company->id,
            'price_satang' => 890000,
        ], $productAttrs));
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

    // -----------------------------------------------------------------
    // B — issuance
    // -----------------------------------------------------------------

    public function test_confirming_payment_issues_a_voucher_snapshotting_product_quota_and_validity(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, [
            'voucher_usage_quota' => 3,
            'voucher_validity_days' => 7,
        ]);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        $voucher = OrderVoucher::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(3, $voucher->usage_quota);
        $this->assertSame(0, $voucher->used_count);
        $this->assertNotNull($voucher->expires_at);
        $this->assertTrue($voucher->expires_at->isSameDay($order->fresh()->paid_at->clone()->addDays(7)));
        $this->assertSame('active', $voucher->status()->value);
    }

    public function test_voucher_usage_quota_and_validity_null_mean_unlimited_and_never_expires(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, [
            'voucher_usage_quota' => null,
            'voucher_validity_days' => null,
        ]);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();

        $voucher = OrderVoucher::where('order_id', $order->id)->firstOrFail();
        $this->assertNull($voucher->usage_quota);
        $this->assertNull($voucher->expires_at);
        $this->assertSame('active', $voucher->status()->value);
    }

    /**
     * MUTATION TEST (TASK-189 §8): force a double-confirm and prove only
     * ONE voucher exists. The `! $alreadyClosed` guard in
     * OrderService::confirmPayment() is the thing under test — this test
     * was run once with that guard's `issueFor()` call left UNGUARDED
     * (always firing) to confirm it fails loudly (2 vouchers / unique
     * constraint violation) before being restored; see the agent's final
     * report for the observed before/after output.
     */
    public function test_voucher_is_issued_exactly_once_even_under_a_double_confirm(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();
        // Second confirm: top-of-method idempotency check short-circuits
        // (order already Paid) — still asserting the OUTCOME (exactly one
        // voucher row), not the code path, so this test stays valid even if
        // the short-circuit implementation changes.
        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();

        $this->assertSame(1, OrderVoucher::where('order_id', $order->id)->count());
    }

    // -----------------------------------------------------------------
    // C — redemption
    // -----------------------------------------------------------------

    public function test_company_admin_can_redeem_an_active_voucher_and_it_writes_an_audit_row(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, ['voucher_usage_quota' => 2]);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);
        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();
        $voucher = OrderVoucher::where('order_id', $order->id)->firstOrFail();

        $this->actingAs($admin)
            ->postJson('/api/v1/vouchers/redeem', ['code' => $voucher->code, 'branch' => 'สาขาสีลม'])
            ->assertOk()
            ->assertJsonPath('data.used_count', 1)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('voucher_redemptions', [
            'order_voucher_id' => $voucher->id,
            'company_id' => $company->id,
            'redeemed_by_user_id' => $admin->id,
            'redeemed_at_branch' => 'สาขาสีลม',
        ]);
    }

    public function test_redemption_at_or_over_quota_is_refused(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, ['voucher_usage_quota' => 1]);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);
        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();
        $voucher = OrderVoucher::where('order_id', $order->id)->firstOrFail();

        // First redemption exhausts the single-use quota.
        $this->actingAs($admin)->postJson('/api/v1/vouchers/redeem', ['code' => $voucher->code])->assertOk();

        // Second redemption is refused — named as exhausted, not a generic error.
        $this->actingAs($admin)
            ->postJson('/api/v1/vouchers/redeem', ['code' => $voucher->code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertSame(1, VoucherRedemption::where('order_voucher_id', $voucher->id)->count());
    }

    public function test_redemption_after_expiry_is_refused(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->paid()->create(['referral_id' => $referral->id]);
        $voucher = OrderVoucher::create([
            'order_id' => $order->id,
            'code' => str_repeat('a', 40),
            'usage_quota' => null,
            'used_count' => 0,
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/vouchers/redeem', ['code' => $voucher->code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertSame(0, $voucher->fresh()->used_count);
    }

    public function test_cross_tenant_redemption_and_lookup_are_refused_with_404(): void
    {
        $companyA = Company::factory()->create();
        $agentA = User::factory()->agent()->create(['company_id' => $companyA->id]);
        $referral = $this->makeReferral($companyA, $agentA, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);
        $this->actingAs($this->paymentConfirmer($companyA))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();
        $voucher = OrderVoucher::where('order_id', $order->id)->firstOrFail();

        $companyB = Company::factory()->create();
        $adminB = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);

        $this->actingAs($adminB)->getJson("/api/v1/vouchers/{$voucher->code}")->assertNotFound();
        $this->actingAs($adminB)
            ->postJson('/api/v1/vouchers/redeem', ['code' => $voucher->code])
            ->assertNotFound();

        $this->assertSame(0, $voucher->fresh()->used_count);
    }

    public function test_a_super_admin_may_redeem_across_companies(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);
        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();
        $voucher = OrderVoucher::where('order_id', $order->id)->firstOrFail();

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->getJson("/api/v1/vouchers/{$voucher->code}")->assertOk();
        $this->actingAs($superAdmin)
            ->postJson('/api/v1/vouchers/redeem', ['code' => $voucher->code])
            ->assertOk();
    }

    public function test_ability_voucher_redeem_denies_agent_but_allows_company_admin_and_super_admin(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        // Agent is denied BEFORE any code lookup happens (FormRequest::authorize()
        // runs before rules()/lookup) — an obviously-invalid code still proves 403.
        $this->actingAs($agent)
            ->postJson('/api/v1/vouchers/redeem', ['code' => 'does-not-matter'])
            ->assertForbidden();
        $this->actingAs($agent)
            ->getJson('/api/v1/vouchers/does-not-matter')
            ->assertForbidden();

        // Company Admin / Super Admin pass the ability gate (reaching the
        // "not found" 422/lookup logic instead of a 403).
        $this->actingAs($admin)
            ->postJson('/api/v1/vouchers/redeem', ['code' => 'does-not-matter'])
            ->assertUnprocessable();
        $this->actingAs($superAdmin)
            ->postJson('/api/v1/vouchers/redeem', ['code' => 'does-not-matter'])
            ->assertUnprocessable();
    }

    // -----------------------------------------------------------------
    // D — shipping capture
    // -----------------------------------------------------------------

    public function test_shipping_fields_are_required_when_product_requires_shipping(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, ['requires_shipping' => true]);
        $order = Order::factory()->create(['referral_id' => $referral->id]);

        // Missing shipping fields — refused.
        $this->postJson("/api/v1/pay/{$order->public_token}/slip", [
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['shipping_recipient_name', 'shipping_phone', 'shipping_address']);

        // With shipping fields — accepted and persisted.
        $this->postJson("/api/v1/pay/{$order->public_token}/slip", [
            'slip' => UploadedFile::fake()->image('slip.jpg'),
            'shipping_recipient_name' => 'สมชาย ใจดี',
            'shipping_phone' => '0812345678',
            'shipping_address' => '123 ถนนสุขุมวิท กรุงเทพฯ',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('สมชาย ใจดี', $order->shipping_recipient_name);
        $this->assertSame('0812345678', $order->shipping_phone);
    }

    public function test_shipping_fields_are_optional_when_product_does_not_require_shipping(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, ['requires_shipping' => false]);
        $order = Order::factory()->create(['referral_id' => $referral->id]);

        $this->postJson("/api/v1/pay/{$order->public_token}/slip", [
            'slip' => UploadedFile::fake()->image('slip.jpg'),
        ])->assertOk()->assertJsonPath('data.status', 'awaiting_verification');

        $order->refresh();
        $this->assertNull($order->shipping_recipient_name);
    }

    public function test_public_pay_page_exposes_requires_shipping_and_voucher_once_paid(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $referral = $this->makeReferral($company, $agent, PipelineStage::Finish1stDoctorMeeting, [
            'requires_shipping' => true,
            'voucher_usage_quota' => 5,
            'voucher_validity_days' => null,
        ]);
        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('data.requires_shipping', true)
            ->assertJsonMissingPath('data.voucher.code'); // not yet paid

        $this->actingAs($this->paymentConfirmer($company))->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();

        $this->getJson("/api/v1/pay/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('data.voucher.usage_quota', 5)
            ->assertJsonPath('data.voucher.used_count', 0)
            ->assertJsonPath('data.voucher.status', 'active');
    }

    public function test_order_voucher_service_is_resolvable_via_container(): void
    {
        // Cheap sanity check that DI wiring for the new Service constructor
        // arg on OrderService did not break container resolution.
        $this->assertInstanceOf(OrderService::class, app(OrderService::class));
    }
}
