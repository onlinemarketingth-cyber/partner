<?php

namespace Tests\Feature\Order;

use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Product;
use App\Models\ProductShareLink;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\Order\ProductShareCheckoutService as Checkout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 2026-09-16 — the public checkout says WHY it refused, in the server log.
 *
 * ── THE SITUATION THIS CLOSES ──
 *
 * A customer on apps.liveto100club.com hit "ไม่สามารถทำรายการสั่งซื้อจาก
 * ลิงก์นี้ได้". The owner opened DevTools and found exactly what this
 * endpoint is designed to show: a 422 and one sentence. Five different
 * conditions produce that response byte for byte, on purpose, so from
 * outside the system the report was unanswerable — the only way through was
 * to hand-check four data conditions and one bot trap against production,
 * per report, forever.
 *
 * ── THE PROPERTY THESE TESTS PROTECT ──
 *
 * There are two halves and they pull against each other, so both are
 * asserted for every one of the five reasons:
 *
 *   1. the LOG distinguishes them — one line, one reason, greppable;
 *   2. the RESPONSE does not — same status, same body, byte for byte.
 *
 * Half 2 is the one that will be broken by accident. The natural next
 * change when somebody is debugging is to put the reason in the response
 * "just while we look into it", and that turns this endpoint into an oracle
 * telling a probing bot whether it was detected or merely sent to a product
 * that is not self-serve. A test that only checked the log would let that
 * through.
 */
class PublicCheckoutRefusalLogTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_REFUSAL = 'ขออภัย ขณะนี้ไม่สามารถทำรายการสั่งซื้อจากลิงก์นี้ได้';

    /**
     * Every reason string the two call sites can emit.
     *
     * Listed here rather than derived, so ADDING a refusal branch without
     * logging it fails a test instead of silently rejoining the pile of
     * indistinguishable ones this whole file exists to break up.
     */
    private const ALL_REASONS = [
        Checkout::REFUSED_AGENT_NOT_CERTIFIED,
        Checkout::REFUSED_PRODUCT_NOT_FOUND,
        Checkout::REFUSED_PRODUCT_NOT_SELLABLE,
        Checkout::REFUSED_PAYMENT_UNREACHABLE,
        Checkout::REFUSED_HONEYPOT,
    ];

    /** @var list<array{string, array<string, mixed>}> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        // A real listener rather than Log::spy(): these tests assert on the
        // CONTEXT payload, and a spy's argument matching makes the failure
        // message unreadable when the payload is one key out.
        $this->captured = [];
        Log::listen(function ($message) {
            $this->captured[] = [$message->message, $message->context];
        });
    }

    /** @return list<array<string, mixed>> the context of every refusal line */
    private function refusals(): array
    {
        return array_values(array_map(
            fn (array $entry) => $entry[1],
            array_filter($this->captured, fn (array $entry) => $entry[0] === Checkout::REFUSAL_LOG_MESSAGE),
        ));
    }

    private function assertRefusedBecause(string $reason, ProductShareLink $link): void
    {
        $refusals = $this->refusals();

        $this->assertCount(1, $refusals, 'expected exactly one refusal line, got '.count($refusals));
        $this->assertSame($reason, $refusals[0]['reason']);
        // The ids are the point of the line — without them a log full of
        // reasons still cannot tell you WHICH link a customer was on.
        $this->assertSame($link->token, $refusals[0]['token']);
        $this->assertSame($link->id, $refusals[0]['link_id']);
        $this->assertSame($link->company_id, $refusals[0]['company_id']);
    }

    // ── Fixtures ───────────────────────────────────────────────────────

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

    /** @return array{0: Company, 1: User, 2: Product, 3: ProductShareLink} */
    private function makeShare(bool $certified = true, bool $medical = false): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        if ($certified) {
            $this->passBasicCert($agent, $company);
        }

        $template = $medical
            ? $this->makeTemplate($company, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT, [
                PipelineStage::CompleteRegistered,
                PipelineStage::WaitingAppointment,
                PipelineStage::Finish1stDoctorMeeting,
                PipelineStage::CompletePayment,
            ])
            : $this->makeTemplate($company, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, [
                PipelineStage::CompleteRegistered,
                PipelineStage::CompletePayment,
            ]);

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
     * A PLATFORM product (company_id null) that this company has not turned
     * on — ADR-040's default-off switch.
     *
     * This, and not a deactivated product, is how REFUSED_PRODUCT_NOT_SELLABLE
     * is actually reached: `is_active = false` is caught one layer earlier by
     * the Controller's resolver and answered with 404 (see the test below).
     * The shared-product switch is invisible to that resolver, because the
     * product itself is perfectly active — it is active for somebody else.
     *
     * @return array{0: ProductShareLink, 1: Product}
     */
    private function sharedProductNotSwitchedOn(): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passBasicCert($agent, $company);

        $product = Product::factory()->create([
            'company_id' => null,
            'is_active' => true,
            'price_satang' => 890000,
        ]);

        // Deliberately NO CompanyProductSetting row: absent and off are the
        // same answer, and absent is the state every company starts in.
        $link = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);

        return [$link, $product];
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

    private function checkout(ProductShareLink $link, array $overrides = [])
    {
        return $this->postJson(
            "/api/v1/public/product-shares/{$link->token}/checkout",
            $this->payload($overrides),
        );
    }

    // ── One test per reason ────────────────────────────────────────────

    public function test_an_uncertified_agents_link_logs_agent_not_certified(): void
    {
        [, , , $link] = $this->makeShare(certified: false);

        $this->checkout($link)->assertStatus(422);

        $this->assertRefusedBecause(Checkout::REFUSED_AGENT_NOT_CERTIFIED, $link);
    }

    public function test_a_link_pointing_at_another_companys_product_logs_product_not_found(): void
    {
        [, , , $link] = $this->makeShare();

        // Another tenant's product. The lookup is company-scoped by hand
        // (BR-6, TenantScope is a no-op on an unauthenticated route), so
        // this is "not found" and not "not sellable".
        $other = Company::factory()->create();
        $strangersProduct = Product::factory()->create(['company_id' => $other->id]);
        $link->forceFill(['product_id' => $strangersProduct->id])->save();

        $this->checkout($link)->assertStatus(422);

        $this->assertRefusedBecause(Checkout::REFUSED_PRODUCT_NOT_FOUND, $link);
    }

    public function test_a_shared_product_this_company_never_switched_on_logs_product_not_sellable(): void
    {
        [$link] = $this->sharedProductNotSwitchedOn();

        $this->checkout($link)->assertStatus(422);

        $this->assertRefusedBecause(Checkout::REFUSED_PRODUCT_NOT_SELLABLE, $link);
    }

    /**
     * A DEACTIVATED product never reaches the Service at all.
     *
     * Worth its own test rather than a comment: it is the case the owner is
     * most likely to hit, and the answer to "why is there no
     * public_checkout.refused line for it" is that the Controller's resolver
     * kills the link first, with a 404. Anyone reading the log needs to know
     * that a MISSING line is itself an answer.
     */
    public function test_a_deactivated_product_is_a_404_before_any_refusal_is_logged(): void
    {
        [, , $product, $link] = $this->makeShare();

        $product->forceFill(['is_active' => false])->save();

        $this->checkout($link)->assertStatus(404);

        $this->assertSame([], $this->refusals());
    }

    public function test_a_journey_that_needs_a_doctor_first_logs_payment_unreachable(): void
    {
        [, , , $link] = $this->makeShare(medical: true);

        $this->checkout($link)->assertStatus(422);

        $this->assertRefusedBecause(Checkout::REFUSED_PAYMENT_UNREACHABLE, $link);
    }

    public function test_a_filled_honeypot_logs_honeypot_and_never_reaches_the_service(): void
    {
        [, , , $link] = $this->makeShare();

        $this->checkout($link, ['hp_field' => 'http://spam.example.com'])->assertStatus(422);

        $this->assertRefusedBecause(Checkout::REFUSED_HONEYPOT, $link);

        // The trap fires BEFORE the service, so nothing was created — the
        // same guarantee the endpoint had before the log existed.
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_the_honeypot_line_carries_the_user_agent_and_the_others_do_not(): void
    {
        // Why only this one: a customer whose password manager filled the
        // hidden field is indistinguishable from a bot by every other field
        // on the line. It is the difference between "we are being probed"
        // and "our own form is rejecting real buyers".
        [, , , $link] = $this->makeShare();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh) TestBrowser/1.0')
            ->postJson(
                "/api/v1/public/product-shares/{$link->token}/checkout",
                $this->payload(['hp_field' => 'x']),
            )->assertStatus(422);

        $this->assertSame('Mozilla/5.0 (Macintosh) TestBrowser/1.0', $this->refusals()[0]['user_agent']);

        $this->captured = [];
        [, , , $other] = $this->makeShare(certified: false);
        $this->checkout($other)->assertStatus(422);

        $this->assertArrayNotHasKey('user_agent', $this->refusals()[0]);
    }

    // ── The half that must NOT distinguish them ────────────────────────

    public function test_all_five_refusals_return_byte_identical_responses(): void
    {
        $bodies = [];
        $statuses = [];

        foreach ($this->fiveRefusals() as $reason => $make) {
            $this->captured = [];
            [$link, $overrides] = $make();

            $response = $this->checkout($link, $overrides);

            $statuses[$reason] = $response->getStatusCode();
            $bodies[$reason] = $response->getContent();

            // …and while we are here, prove the log DID tell them apart.
            $this->assertSame($reason, $this->refusals()[0]['reason']);
        }

        $this->assertCount(5, $bodies);
        $this->assertSame([422], array_values(array_unique($statuses)));
        // ONE distinct body across all five. Not "all contain the message" —
        // identical, which is the property that survives somebody appending
        // a debug field to one branch.
        $this->assertCount(1, array_unique($bodies), 'refusal bodies diverged: '.json_encode($bodies, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString(self::GENERIC_REFUSAL, json_decode((string) reset($bodies), true)['message'] ?? '');
    }

    public function test_no_refusal_line_carries_the_customers_personal_data(): void
    {
        // PDPA — a refused checkout has no order and no consent record to
        // hang a phone number on, so logging one would create personal data
        // in a file with no retention policy and no owner.
        foreach ($this->fiveRefusals() as $make) {
            $this->captured = [];
            [$link, $overrides] = $make();

            $this->checkout($link, $overrides)->assertStatus(422);

            $line = json_encode($this->refusals()[0], JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('สมชาย ใจดี', (string) $line);
            $this->assertStringNotContainsString('0812345678', (string) $line);
            $this->assertStringNotContainsString('somchai@example.com', (string) $line);
        }
    }

    public function test_every_declared_reason_is_actually_reachable(): void
    {
        $seen = [];

        foreach ($this->fiveRefusals() as $make) {
            $this->captured = [];
            [$link, $overrides] = $make();
            $this->checkout($link, $overrides);
            $seen[] = $this->refusals()[0]['reason'];
        }

        sort($seen);
        $expected = self::ALL_REASONS;
        sort($expected);

        $this->assertSame($expected, $seen);
    }

    public function test_a_successful_checkout_logs_no_refusal(): void
    {
        [, , , $link] = $this->makeShare();

        $this->checkout($link)->assertOk();

        $this->assertSame([], $this->refusals());
    }

    /**
     * One builder per reason, each returning [link, payload overrides].
     *
     * Closures rather than a data provider: a provider runs before the
     * application boots, and every one of these needs the database.
     *
     * @return array<string, callable(): array{0: ProductShareLink, 1: array<string, mixed>}>
     */
    private function fiveRefusals(): array
    {
        return [
            Checkout::REFUSED_AGENT_NOT_CERTIFIED => function () {
                [, , , $link] = $this->makeShare(certified: false);

                return [$link, []];
            },
            Checkout::REFUSED_PRODUCT_NOT_FOUND => function () {
                [, , , $link] = $this->makeShare();
                $other = Company::factory()->create();
                $link->forceFill(['product_id' => Product::factory()->create(['company_id' => $other->id])->id])->save();

                return [$link, []];
            },
            Checkout::REFUSED_PRODUCT_NOT_SELLABLE => function () {
                [$link] = $this->sharedProductNotSwitchedOn();

                return [$link, []];
            },
            Checkout::REFUSED_PAYMENT_UNREACHABLE => function () {
                [, , , $link] = $this->makeShare(medical: true);

                return [$link, []];
            },
            Checkout::REFUSED_HONEYPOT => function () {
                [, , , $link] = $this->makeShare();

                return [$link, ['hp_field' => 'http://spam.example.com']];
            },
        ];
    }
}
