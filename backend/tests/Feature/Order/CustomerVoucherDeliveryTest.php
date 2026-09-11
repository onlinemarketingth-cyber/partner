<?php

namespace Tests\Feature\Order;

use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Mail\OrderPaymentConfirmedMail;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Order;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\PlatformMailSetting;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Platform\PlatformMailSettingService;
use App\Support\PortalOrigin;
use App\Support\VoucherCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 2026-09-10 (human: "หลังจากชำระเงินสำเร็จใน frontend แล้ว ลูกค้าจะได้รหัส
 * ยืนยันใช้บริการได้อย่างไร").
 *
 * The honest answer was: only by still having the /pay/{token} tab open. The
 * voucher code existed in exactly one place, and the confirmation email —
 * which is the durable copy a customer keeps — carried an order number and a
 * link and nothing else.
 *
 * Worse, that link was always built from the canonical FRONTEND_URL, while the
 * portal answers on more than one first-party domain. A customer who read the
 * page, typed their details and paid on the parked alias received a receipt
 * pointing at a brand they had never seen, which reads as phishing.
 *
 * This file pins both halves: what the email carries, and which domain it
 * points at — including the reason the domain cannot simply be taken from the
 * request.
 */
class CustomerVoucherDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const CANONICAL = 'https://partner.example.test';

    private const ALIAS = 'https://apps.example-alias.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.agent_portal.frontend_url' => self::CANONICAL,
            'services.agent_portal.extra_origins' => self::ALIAS,
        ]);
    }

    // ── Which domain a customer is sent back to ──────────────────────

    public function test_an_alias_this_deployment_serves_is_kept(): void
    {
        $this->assertSame(self::ALIAS, PortalOrigin::resolve(self::ALIAS));
    }

    public function test_a_domain_nobody_declared_is_refused(): void
    {
        /*
         * THE ONE THAT MATTERS. On a public checkout the Origin header is
         * attacker-controlled. If it were written through as-is, anyone could
         * make this system send a customer an email — from our address, about
         * a real order — linking to their own copy of our payment page. A
         * phishing kit we host and sign.
         */
        $this->assertNull(PortalOrigin::resolve('https://evil.example'));
        $this->assertNull(PortalOrigin::resolve('https://partner.example.test.evil.example'));
    }

    public function test_the_canonical_host_is_stored_as_nothing_at_all(): void
    {
        // So a deployment that MOVES domain moves every old order's links with
        // it. Only a deliberate alias is pinned to a name.
        $this->assertNull(PortalOrigin::resolve(self::CANONICAL));
        $this->assertNull(PortalOrigin::resolve(null));
    }

    public function test_an_order_with_no_origin_falls_back_to_the_canonical_host(): void
    {
        $order = Order::factory()->create(['checkout_origin' => null]);

        $this->assertSame(
            self::CANONICAL.'/pay/'.$order->public_token,
            PortalOrigin::payUrl($order),
        );
    }

    public function test_an_order_bought_on_the_alias_links_back_to_the_alias(): void
    {
        $order = Order::factory()->create(['checkout_origin' => self::ALIAS]);

        $this->assertSame(
            self::ALIAS.'/pay/'.$order->public_token,
            PortalOrigin::payUrl($order),
        );
    }

    // ── What the confirmation email carries ──────────────────────────

    public function test_the_email_carries_the_code_the_customer_needs(): void
    {
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder(['voucher_usage_quota' => 3, 'voucher_validity_days' => 30]);
        $order->forceFill(['checkout_origin' => self::ALIAS])->save();

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        // The code AS PRINTED (ABC-123). 2026-09-10 shortened it to six
        // characters so staff can key it; the email shows the same grouping
        // the card and the pay page do, so a customer reading it out and a
        // person typing it are looking at the same thing.
        $code = VoucherCode::format($order->fresh()->voucher->code);

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) use ($code, $order) {
            $html = $mail->render();

            return str_contains($html, $code)
                // What it entitles them to, and for how long — the two facts a
                // code is useless without.
                && str_contains($html, 'ตรวจสุขภาพประจำปี')
                && str_contains($html, 'จำนวนสิทธิ์')
                && str_contains($html, 'ใช้ได้ถึง')
                // And back to the domain they actually bought from.
                && str_contains($html, self::ALIAS.'/pay/'.$order->public_token);
        });
    }

    /**
     * 2026-09-11 (human: "ปรับ email ให้เป็นรูปแบบสากล เรียน ใส่ชื่อลูกค้าจาก
     * ระบบ รวมถึง link ดูรายละเอียดการสั่งซื้อ และ QR สร้างเป็น UI แบบปุ่มให้
     * ชัดเจน").
     */
    public function test_the_email_addresses_the_customer_by_the_name_on_file(): void
    {
        /*
         * It opened "เรียนคุณลูกค้า" to somebody whose name is in the clients
         * table. Addressing a person as "customer" while holding their name is
         * what makes a receipt read as bulk mail — and bulk mail is deleted
         * unread, taking the voucher code with it.
         */
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder(['voucher_usage_quota' => 1, 'voucher_validity_days' => null]);
        $order->client->forceFill(['name' => 'สมชาย ใจดี'])->save();

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) {
            return str_contains($mail->render(), 'เรียน คุณสมชาย ใจดี');
        });
    }

    public function test_an_order_with_no_name_is_not_addressed_to_nobody(): void
    {
        // "เรียน คุณ" trailing into nothing is worse than the generic form, and
        // an order an agent keys in by hand may genuinely have no name.
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder(['voucher_usage_quota' => 1, 'voucher_validity_days' => null]);
        $order->client->forceFill(['name' => ''])->save();

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) {
            return str_contains($mail->render(), 'เรียน คุณลูกค้า');
        });
    }

    public function test_the_one_action_looks_like_a_button(): void
    {
        /*
         * The link was an underlined sentence at the bottom of a stack of
         * paragraphs — the same shape as the text around it, which is the
         * shape people skim past. It is now a filled cell.
         *
         * Asserted as a BACKGROUND-BEARING CELL containing the link rather
         * than on any one style string: `bgcolor` is the attribute Outlook
         * reads and the one a "make it a button" change must not lose.
         */
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder(['voucher_usage_quota' => 1, 'voucher_validity_days' => null]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) use ($order) {
            $html = $mail->render();

            return str_contains($html, 'bgcolor=')
                && str_contains($html, 'ดูรายละเอียดคำสั่งซื้อ และ QR เข้ารับบริการ')
                // And the address is still printed in full underneath: a
                // client that strips the button must not strip the only route
                // back to the voucher.
                && substr_count($html, PortalOrigin::payUrl($order)) >= 2;
        });
    }

    public function test_the_receipt_carries_what_a_receipt_is_kept_for(): void
    {
        // Forwarded to an accounts department, or held against a card
        // statement. "฿8,900" with no order number answers nothing.
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder(['voucher_usage_quota' => 1, 'voucher_validity_days' => null]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) use ($order) {
            $html = $mail->render();

            return str_contains($html, $order->order_number)
                && str_contains($html, '฿8,900.00')
                && str_contains($html, 'ตรวจสุขภาพประจำปี')
                && str_contains($html, 'ชำระเมื่อ');
        });
    }

    /**
     * 2026-09-11 (human: "ขอรายละเอียด ชื่อ รายละเอียดสินค้า เข้าไปใน email
     * ด้วยครับ").
     */
    public function test_the_email_carries_what_the_customer_actually_bought(): void
    {
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder([
            'voucher_usage_quota' => 1,
            'voucher_validity_days' => null,
            'description' => '<p>โปรแกรมตรวจสุขภาพเชิงป้องกัน <strong>ครบ 1 ปี</strong></p><ul><li>ตรวจเลือด 38 รายการ</li></ul>',
            'spec_description' => '<p>รวมค่าแพทย์และค่าบริการแล้ว</p>',
        ]);

        $order->product->specs()->create([
            'company_id' => $company->id,
            'spec_group' => 'สิ่งที่ได้รับ',
            'spec_key' => 'Health Tracker V8',
            'spec_value' => '1 เครื่อง',
            'sort_order' => 1,
        ]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'รายละเอียดสินค้า')
                && str_contains($html, 'ตรวจเลือด 38 รายการ')
                && str_contains($html, 'รวมค่าแพทย์และค่าบริการแล้ว')
                // The spec sheet, group heading and all.
                && str_contains($html, 'สิ่งที่ได้รับ')
                && str_contains($html, 'Health Tracker V8')
                && str_contains($html, '1 เครื่อง');
        });
    }

    public function test_the_formatting_an_admin_typed_survives_as_formatting(): void
    {
        /*
         * The ONE field in this email that is not escaped. Its markup is
         * already clean — App\Support\RichText gates these columns on WRITE
         * against a twelve-element allowlist — so escaping here would print
         * `<strong>` at a customer instead of making a word bold.
         *
         * The second half of this test is the half that matters: the same
         * value going through the same email must not be able to carry a
         * script tag. It cannot, because the database cannot hold one — and
         * this asserts that end of it rather than trusting the memory of it.
         */
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder([
            'voucher_usage_quota' => 1,
            'voucher_validity_days' => null,
            'description' => '<p>ดูแล <strong>ต่อเนื่อง</strong></p>',
        ]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) {
            $html = $mail->render();

            return str_contains($html, '<strong')
                && ! str_contains($html, '&lt;strong&gt;')
                && ! str_contains($html, '<script');
        });
    }

    public function test_no_product_section_appears_when_there_is_nothing_to_say(): void
    {
        // A heading over an empty box reads as content that failed to load.
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder([
            'voucher_usage_quota' => 1,
            'voucher_validity_days' => null,
            'description' => null,
            'spec_description' => null,
        ]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) {
            return ! str_contains($mail->render(), 'รายละเอียดสินค้า');
        });
    }

    public function test_the_message_is_held_to_a_readable_width(): void
    {
        /*
         * From the screenshot that prompted the redesign: the code box ran off
         * the right edge of the window, because the body was bare <p> tags at
         * whatever width the client happened to be. A fixed-width table inside
         * a full-width wrapper is the only centring Outlook honours, so this
         * pins both halves.
         */
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder(['voucher_usage_quota' => 1, 'voucher_validity_days' => null]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'max-width:600px')
                && str_contains($html, 'width="600"');
        });
    }

    public function test_the_inbox_preview_shows_the_code_rather_than_the_greeting(): void
    {
        // Without a preheader the preview line is "เรียน คุณ…" — the one part
        // of the message carrying no information. With it, a customer at a
        // counter often does not have to open the email at all.
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder(['voucher_usage_quota' => 1, 'voucher_validity_days' => null]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        $code = VoucherCode::format($order->fresh()->voucher->code);

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) use ($code) {
            return str_contains($mail->render(), 'รหัสเข้ารับบริการของคุณคือ '.$code);
        });
    }

    public function test_the_email_promises_no_expiry_that_does_not_exist(): void
    {
        // Null quota/validity mean unlimited and never-expiring. Printing
        // "ไม่มีวันหมดอายุ" would be this system making a promise on the
        // product's behalf, which a later product change would falsify.
        Mail::fake();
        $this->enablePlatformMail();

        [$company, $order] = $this->payableOrder(['voucher_usage_quota' => null, 'voucher_validity_days' => null]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        Mail::assertSent(OrderPaymentConfirmedMail::class, function (OrderPaymentConfirmedMail $mail) {
            $html = $mail->render();

            // 'จำนวนสิทธิ์:' with the colon — the FIELD. The block's closing
            // sentence mentions จำนวนสิทธิ์คงเหลือ unconditionally, which is a
            // pointer to the page, not a claim about this voucher.
            return ! str_contains($html, 'ใช้ได้ถึง') && ! str_contains($html, 'จำนวนสิทธิ์:');
        });
    }

    // ── The address the code is sent to ──────────────────────────────

    public function test_a_self_serve_checkout_now_requires_an_email(): void
    {
        /*
         * It was optional, copied from the affiliate LEAD form where that is
         * right. This form takes money and gives back a code — without an
         * address the code lives only in the tab the customer is about to
         * close, with no way for us to send it and no way for them to ask.
         */
        $link = $this->shareLink();

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", [
            'name' => 'สมชาย ใจดี',
            'phone' => '0812345678',
            'consent' => '1',
        ])->assertJsonValidationErrors('email');
    }

    public function test_the_checkout_remembers_the_domain_the_customer_bought_on(): void
    {
        $link = $this->shareLink();

        $response = $this->withHeader('Origin', self::ALIAS)
            ->postJson("/api/v1/public/product-shares/{$link->token}/checkout", [
                'name' => 'สมชาย ใจดี',
                'phone' => '0812345678',
                'email' => 'customer@example.com',
                'consent' => '1',
            ])->assertOk();

        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame(self::ALIAS, $order->checkout_origin);
        // And the URL handed straight back to the browser agrees, so the
        // customer never crosses a domain mid-purchase.
        $this->assertStringStartsWith(self::ALIAS.'/pay/', $response->json('pay_url'));
    }

    public function test_a_forged_origin_is_not_remembered(): void
    {
        $link = $this->shareLink();

        $this->withHeader('Origin', 'https://evil.example')
            ->postJson("/api/v1/public/product-shares/{$link->token}/checkout", [
                'name' => 'สมชาย ใจดี',
                'phone' => '0812345678',
                'email' => 'customer@example.com',
                'consent' => '1',
            ])->assertOk();

        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertNull($order->checkout_origin);
        $this->assertStringStartsWith(self::CANONICAL.'/pay/', PortalOrigin::payUrl($order));
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /**
     * A certified agent's share link for a product that can be bought
     * self-serve. Mirrors ProductShareCheckoutTest's own setup.
     */
    private function shareLink(): ProductShareLink
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);

        // register -> pay. ADR-026 §3.7: a product whose journey cannot reach
        // complete_payment on its first move is not self-serve at all, and the
        // checkout refuses it — so without this the fixture would 422 for a
        // reason that has nothing to do with what is under test.
        $template = PipelineTemplate::create([
            'company_id' => $company->id,
            'key' => PipelineTemplate::KEY_DIRECT_SALE_DEFAULT,
            'name' => 'Direct sale',
            'is_system' => true,
        ]);
        foreach ([PipelineStage::CompleteRegistered, PipelineStage::CompletePayment] as $position => $stage) {
            PipelineTemplateStage::create([
                'company_id' => $company->id,
                'pipeline_template_id' => $template->id,
                'stage' => $stage,
                'position' => $position,
            ]);
        }

        $product = Product::factory()->create([
            'company_id' => $company->id,
            'price_satang' => 890000,
            'pipeline_template_id' => $template->id,
        ]);

        return ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);
    }

    /**
     * An order sitting at awaiting-verification, one confirm away from a
     * voucher.
     *
     * @param  array<string, mixed>  $productAttributes
     * @return array{0: Company, 1: Order}
     */
    private function payableOrder(array $productAttributes): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = $this->passBasicCert($agent, $company);

        $product = Product::factory()->create(array_merge([
            'company_id' => $company->id,
            'name' => 'ตรวจสุขภาพประจำปี',
            'price_satang' => 890000,
        ], $productAttributes));

        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
        ]);

        $client = Client::factory()->create([
            'company_id' => $company->id,
            'referring_agent_id' => $agent->id,
            'email' => 'customer@example.com',
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

        return [$company, Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id])];
    }

    private function passBasicCert(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        return $tier;
    }

    /** Platform mail on, and the shared cache key told about it. */
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
        Cache::forget(PlatformMailSettingService::CACHE_KEY);
    }
}
