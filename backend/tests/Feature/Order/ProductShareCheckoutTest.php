<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Enums\PromotionStatus;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\Company;
use App\Models\Order;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Product;
use App\Models\ProductPricePromotion;
use App\Models\ProductShareLink;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-136 — POST /api/v1/public/product-shares/{token}/checkout.
 *
 * An anonymous visitor turns a shared product link into a payable order:
 * Client + Referral + Order in one transaction, response is a pay_url and
 * nothing else.
 *
 * The tests that matter most here are the negative ones. This is the
 * second unauthenticated WRITE endpoint in the codebase and the first that
 * creates a money record, so "what it refuses, and how little it says
 * while refusing" is the feature.
 */
class ProductShareCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /** The single generic refusal every failure path must produce (§6). */
    private const GENERIC_REFUSAL = 'ขออภัย ขณะนี้ไม่สามารถทำรายการสั่งซื้อจากลิงก์นี้ได้';

    /**
     * @param  list<PipelineStage>  $stages
     */
    private function makeTemplate(Company $company, string $key, array $stages): PipelineTemplate
    {
        $template = PipelineTemplate::create([
            'company_id' => $company->id,
            'key' => $key,
            'name' => ucfirst(str_replace('_', ' ', $key)),
            'is_system' => true,
        ]);

        foreach ($stages as $position => $stage) {
            PipelineTemplateStage::create([
                'company_id' => $company->id,
                'pipeline_template_id' => $template->id,
                'stage' => $stage,
                'position' => $position,
            ]);
        }

        return $template;
    }

    private function directSaleTemplate(Company $company): PipelineTemplate
    {
        return $this->makeTemplate($company, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::CompletePayment,
        ]);
    }

    private function medicalTemplate(Company $company): PipelineTemplate
    {
        return $this->makeTemplate($company, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT, [
            PipelineStage::CompleteRegistered,
            PipelineStage::WaitingAppointment,
            PipelineStage::Finish1stDoctorMeeting,
            PipelineStage::CompletePayment,
            PipelineStage::OngoingNextMeeting,
        ]);
    }

    private function passBasicCert(User $agent, Company $company): void
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);
    }

    /**
     * A certified agent sharing a product whose journey is
     * register -> pay, i.e. the case ADR-026 exists to unblock.
     *
     * @return array{0: Company, 1: User, 2: Product, 3: ProductShareLink}
     */
    private function makeSelfServeShare(bool $certified = true): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        if ($certified) {
            $this->passBasicCert($agent, $company);
        }

        $template = $this->directSaleTemplate($company);
        // BR-3 — integer satang, never float.
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'price_satang' => 890000,
            'pipeline_template_id' => $template->id,
        ]);
        $link = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);

        return [$company, $agent, $product, $link];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'สมชาย ใจดี',
            'phone' => '0812345678',
            'email' => 'somchai@example.com',
            'consent' => true,
        ], $overrides);
    }

    // ── Happy path ─────────────────────────────────────────────────────

    public function test_a_visitor_can_check_out_and_receives_a_pay_url(): void
    {
        [$company, $agent, $product, $link] = $this->makeSelfServeShare();

        $response = $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())
            ->assertOk();

        $this->assertStringContainsString('/pay/', (string) $response->json('pay_url'));

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $this->assertStringEndsWith("/pay/{$order->public_token}", (string) $response->json('pay_url'));
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame($company->id, $order->company_id);
        $this->assertSame($agent->id, $order->agent_id);
        $this->assertSame($product->id, $order->product_id);
        $this->assertSame(890000, $order->amount_satang);

        // BR-4 — checkout creates a PENDING order. Commission fires at
        // Complete Payment and nowhere else; nothing has been paid yet.
        $this->assertDatabaseCount('commission_ledger', 0);
    }

    public function test_the_response_body_contains_only_a_pay_url(): void
    {
        // §6 / PDPA — the natural instinct when debugging is to "just add
        // the order id"; this test exists to make that a failing change.
        [, $agent, , $link] = $this->makeSelfServeShare();

        $response = $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())
            ->assertOk();

        // Exactly one key. This alone rules out ids, company_id, order
        // number and amount — anything added later fails here first.
        $this->assertSame(['pay_url'], array_keys($response->json()));

        // And nothing PDPA-sensitive smuggled inside the one value.
        // (No assertion on the numeric company id: the pay token is 40
        // random alphanumerics and would collide with a small integer.)
        $body = $response->getContent();
        $this->assertStringNotContainsString($agent->name, $body);
        $this->assertStringNotContainsString('สมชาย ใจดี', $body);
        $this->assertStringNotContainsString('0812345678', $body);
    }

    public function test_checkout_creates_client_referral_and_stage_log_attributed_to_the_links_agent(): void
    {
        [$company, $agent, $product, $link] = $this->makeSelfServeShare();

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();

        $client = Client::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($company->id, $client->company_id);
        $this->assertSame($agent->id, $client->referring_agent_id);
        // ADR-019 — distinguishes a self-serve purchase from lead capture.
        $this->assertSame('Product Share', $client->lead_source);
        // PDPA (§6) — the visitor's explicit consent is recorded.
        $this->assertNotNull($client->consent_given_at);

        $referral = Referral::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($agent->id, $referral->agent_id);
        $this->assertSame($product->id, $referral->product_id);
        $this->assertSame(PipelineStage::CompleteRegistered, $referral->current_stage);
        // ADR-026 §3.4 — the template is snapshotted at creation.
        $this->assertSame($product->pipeline_template_id, $referral->pipeline_template_id);

        // §4.3 audit trail, credited to the link's agent (no authenticated
        // actor exists on a public route).
        $this->assertDatabaseHas('pipeline_stage_logs', [
            'referral_id' => $referral->id,
            'from_stage' => null,
            'to_stage' => PipelineStage::CompleteRegistered->value,
            'changed_by_user_id' => $agent->id,
        ]);
    }

    public function test_a_self_serve_referral_leaves_branch_null_rather_than_inventing_one(): void
    {
        // Human + ag-lead ruling 2026-08-08 (TASK-132 §"Decision —
        // referrals.branch"): NULL means "this sale did not happen at a
        // branch". A placeholder like 'ONLINE' or '-' was rejected because
        // it becomes indistinguishable from a real branch name.
        [, , , $link] = $this->makeSelfServeShare();

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();

        $this->assertNull(Referral::withoutGlobalScopes()->firstOrFail()->branch);
    }

    public function test_the_order_is_immediately_confirmable_by_the_agent(): void
    {
        // The whole point of ADR-026 §3.7 — the customer's order must not
        // land in a state nobody can close.
        [, $agent, , $link] = $this->makeSelfServeShare();

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($agent)
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(PipelineStage::CompletePayment, Referral::withoutGlobalScopes()->firstOrFail()->current_stage);
    }

    // ── Refusals — all indistinguishable ───────────────────────────────

    public function test_a_medical_journey_product_cannot_be_checked_out(): void
    {
        // ADR-026 §3.7 — payment is not reachable from the entry stage, so
        // an order created here could be paid but never confirmed.
        // Computed from the template, not from a stage name in an if().
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);

        $product = Product::factory()->create([
            'company_id' => $company->id,
            'pipeline_template_id' => $this->medicalTemplate($company)->id,
        ]);
        $link = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', self::GENERIC_REFUSAL);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('referrals', 0);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_an_agent_without_basic_certification_writes_nothing_and_says_nothing(): void
    {
        // BR-1, gated on the LINK's agent (sprint risk R3). The message is
        // identical to every other refusal — a public endpoint must never
        // be an oracle for another company's internal state.
        [, , , $link] = $this->makeSelfServeShare(certified: false);

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', self::GENERIC_REFUSAL);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('referrals', 0);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_a_filled_honeypot_writes_nothing_and_is_indistinguishable_from_any_other_refusal(): void
    {
        [, , , $link] = $this->makeSelfServeShare();

        $this->postJson(
            "/api/v1/public/product-shares/{$link->token}/checkout",
            $this->payload(['hp_field' => 'http://spam.example.com']),
        )
            ->assertUnprocessable()
            // Byte-identical to the BR-1 and medical-template refusals, so
            // a probing bot cannot learn it was caught. See the
            // Controller's docblock for why a 200 is not possible here.
            ->assertJsonPath('message', self::GENERIC_REFUSAL);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('referrals', 0);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_a_revoked_link_404s(): void
    {
        [$company, $agent, $product] = $this->makeSelfServeShare();
        $revoked = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'revoked_at' => now(),
        ]);

        $this->postJson("/api/v1/public/product-shares/{$revoked->token}/checkout", $this->payload())
            ->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_unknown_token_404s_even_with_an_incomplete_payload(): void
    {
        // The Form Request aborts 404 before validating fields, so a bad
        // token never surfaces as "phone is required".
        $this->postJson('/api/v1/public/product-shares/not-a-real-token/checkout', [])
            ->assertNotFound();
    }

    public function test_consent_is_required(): void
    {
        // PDPA (§6) — no consent, no collection.
        [, , , $link] = $this->makeSelfServeShare();

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload(['consent' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent');

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_a_client_supplied_branch_is_ignored_not_written(): void
    {
        // §6 "never trust the client" — the field is not in rules(), so it
        // cannot reach the Referral even if posted.
        [, , , $link] = $this->makeSelfServeShare();

        $this->postJson(
            "/api/v1/public/product-shares/{$link->token}/checkout",
            $this->payload(['branch' => 'สาขาปลอม']),
        )->assertOk();

        $this->assertNull(Referral::withoutGlobalScopes()->firstOrFail()->branch);
    }

    // ── Duplicate submits ──────────────────────────────────────────────

    public function test_a_duplicate_submit_reuses_the_pending_order_instead_of_creating_a_second(): void
    {
        // TODO: CONFIRM (business rule) — the window length N is a BR-7
        // value the human has not given (ADR-026 §5 open item 3). This
        // test asserts the BEHAVIOUR (reuse, don't duplicate), which IS
        // specified, using the placeholder constant.
        [, , , $link] = $this->makeSelfServeShare();

        $first = $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();
        $second = $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();

        $this->assertSame($first->json('pay_url'), $second->json('pay_url'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('referrals', 1);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_a_different_phone_number_is_a_different_purchase(): void
    {
        [, , , $link] = $this->makeSelfServeShare();

        $first = $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();
        $second = $this->postJson(
            "/api/v1/public/product-shares/{$link->token}/checkout",
            $this->payload(['phone' => '0899999999']),
        )->assertOk();

        $this->assertNotSame($first->json('pay_url'), $second->json('pay_url'));
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_a_submit_outside_the_window_creates_a_new_order(): void
    {
        [, , , $link] = $this->makeSelfServeShare();

        $first = $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();

        // Well past any plausible value of the placeholder window.
        $this->travel(1)->days();

        $second = $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();

        $this->assertNotSame($first->json('pay_url'), $second->json('pay_url'));
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_a_paid_order_is_never_handed_back_as_a_reusable_one(): void
    {
        // Reuse must not tell a customer who already paid to pay again.
        [, $agent, , $link] = $this->makeSelfServeShare();

        $first = $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($agent)->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk();

        $second = $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();

        $this->assertNotSame($first->json('pay_url'), $second->json('pay_url'));
        $this->assertDatabaseCount('orders', 2);
    }

    // ── Risk R1: advertised price == charged price ─────────────────────

    public function test_the_order_is_created_at_the_promotional_price_the_share_page_advertises(): void
    {
        // TASK-132 §Risks R1. Before this fix OrderService snapshotted the
        // LIST price while CommissionService computed from the DISCOUNTED
        // one — "the page said 7,500 and I was charged 8,900".
        [$company, , $product, $link] = $this->makeSelfServeShare();

        ProductPricePromotion::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'discounted_price_satang' => 750000, // 7,500 THB
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ]);

        // What the public share page advertises...
        $this->getJson("/api/v1/public/product-shares/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.product.price_satang', 890000)
            ->assertJsonPath('data.product.payable_price_satang', 750000)
            ->assertJsonPath('data.product.promotional_price_satang', 750000);

        // ...must equal what the customer is charged.
        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();

        $this->assertSame(750000, Order::withoutGlobalScopes()->firstOrFail()->amount_satang);
    }

    public function test_an_inactive_promotion_leaves_the_list_price_in_place(): void
    {
        [$company, , $product, $link] = $this->makeSelfServeShare();

        ProductPricePromotion::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'discounted_price_satang' => 750000,
            'status' => PromotionStatus::Active,
            // Already finished — isCurrentlyActive() is false.
            'starts_at' => now()->subDays(10)->toDateString(),
            'ends_at' => now()->subDays(2)->toDateString(),
        ]);

        $this->getJson("/api/v1/public/product-shares/{$link->token}")
            ->assertOk()
            ->assertJsonPath('data.product.payable_price_satang', 890000)
            ->assertJsonPath('data.product.promotional_price_satang', null);

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();

        $this->assertSame(890000, Order::withoutGlobalScopes()->firstOrFail()->amount_satang);
    }

    public function test_the_share_page_advertises_whether_checkout_is_possible(): void
    {
        // TASK-137 needs this to decide between a "ซื้อเลย" CTA and the
        // view-only page. It is the same predicate the POST enforces.
        [$company, $agent, , $selfServeLink] = $this->makeSelfServeShare();

        $this->getJson("/api/v1/public/product-shares/{$selfServeLink->token}")
            ->assertOk()
            ->assertJsonPath('data.product.can_checkout', true);

        $medicalProduct = Product::factory()->create([
            'company_id' => $company->id,
            'pipeline_template_id' => $this->medicalTemplate($company)->id,
        ]);
        $medicalLink = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $medicalProduct->id,
        ]);

        $this->getJson("/api/v1/public/product-shares/{$medicalLink->token}")
            ->assertOk()
            ->assertJsonPath('data.product.can_checkout', false);
    }

    // ── BR-6 tenant isolation ──────────────────────────────────────────

    public function test_everything_written_belongs_to_the_links_own_company(): void
    {
        // There is no authenticated user on this route, so TenantScope is
        // a complete no-op (§5) — the company MUST come from the link, and
        // a second, unrelated company must end up with nothing.
        [$companyA, , , $linkA] = $this->makeSelfServeShare();
        [$companyB] = $this->makeSelfServeShare();

        $this->postJson("/api/v1/public/product-shares/{$linkA->token}/checkout", $this->payload())->assertOk();

        $this->assertSame($companyA->id, Client::withoutGlobalScopes()->firstOrFail()->company_id);
        $this->assertSame($companyA->id, Referral::withoutGlobalScopes()->firstOrFail()->company_id);
        $this->assertSame($companyA->id, Order::withoutGlobalScopes()->firstOrFail()->company_id);

        $this->assertSame(0, Order::withoutGlobalScopes()->where('company_id', $companyB->id)->count());
        $this->assertSame(0, Referral::withoutGlobalScopes()->where('company_id', $companyB->id)->count());
        $this->assertSame(0, Client::withoutGlobalScopes()->where('company_id', $companyB->id)->count());
    }

    public function test_an_agent_from_another_company_cannot_see_the_resulting_order(): void
    {
        // IDOR (§5 rule 5) — the order exists, but not for them.
        [, , , $link] = $this->makeSelfServeShare();
        [$companyB] = $this->makeSelfServeShare();
        $outsider = User::factory()->companyAdmin()->create(['company_id' => $companyB->id]);

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", $this->payload())->assertOk();
        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($outsider)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertNotFound();
    }
}
