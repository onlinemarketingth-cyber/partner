<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\PipelineStage;
use App\Models\Company;
use App\Models\Order;
use App\Models\PipelineTemplate;
use App\Models\Product;
use App\Models\Referral;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Pipeline\PipelineTemplateProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-10 (human, from production: "1 คำสั่งซื้อผ่าน ORD-MWJTV2QV สำเร็จ
 * แล้ว … แต่ระบบขึ้นรอชำระ").
 *
 * ── THE SHAPE OF THAT ORDER ──
 *
 * Card charged, charge id on the row, status still not paid. It got there
 * because OrderService::confirmPayment() refused: the referral's snapshotted
 * journey was Medical Package, whose next step is an appointment, so a
 * payment could not be accepted yet. GatewayPaymentService catches that
 * refusal on purpose and leaves the receipt behind as a findable residue.
 *
 * Deploying the journey fix does not clear it — a referral snapshots its
 * journey at creation, so orders already taken keep the old one. This is the
 * command that clears them, and these are the two halves of its judgement:
 * it repairs a referral that has not moved, and it refuses to touch one that
 * has.
 */
class RetryStuckGatewayConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $agent;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['name' => 'AIA']);
        app(PipelineTemplateProvisioner::class)->provision($this->company);

        $this->agent = User::factory()->agent()->create(['company_id' => $this->company->id]);

        // A สินค้ากลาง on the platform's ขายตรง journey — the shape the fixed
        // catalogue leaves behind.
        $this->product = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'name' => 'GENESENN Health Tracker V5 Vital Blueprint',
            'price_satang' => 890000,
            'is_active' => true,
            'commission_plan_type' => 'unilevel',
            'pipeline_template_id' => $this->journey(null, PipelineTemplate::KEY_DIRECT_SALE_DEFAULT)->id,
        ]);
    }

    private function journey(?int $companyId, string $key): PipelineTemplate
    {
        return PipelineTemplate::withoutGlobalScopes()
            ->where('key', $key)
            ->when($companyId === null, fn ($q) => $q->whereNull('company_id'))
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->firstOrFail();
    }

    /**
     * An order in exactly the reported state: money in, sale not closed,
     * referral stranded on the journey the product used to have.
     */
    protected function stuckOrder(PipelineStage $stage = PipelineStage::CompleteRegistered, string $number = 'ORD-MWJTV2QV'): Order
    {
        $referral = Referral::factory()->create([
            'company_id' => $this->company->id,
            'agent_id' => $this->agent->id,
            'product_id' => $this->product->id,
            'pipeline_template_id' => $this->journey($this->company->id, PipelineTemplate::KEY_MEDICAL_PACKAGE_DEFAULT)->id,
            'current_stage' => $stage->value,
        ]);

        $order = Order::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $this->company->id,
            'referral_id' => $referral->id,
            'client_id' => $referral->client_id,
            'agent_id' => $this->agent->id,
            'product_id' => $this->product->id,
            'order_number' => $number,
            'amount_satang' => 890000,
            'status' => OrderStatus::Pending->value,
            'payment_method' => 'bank_transfer',
            'payment_provider' => 'stripe',
            'public_token' => Str::random(40),
        ]);

        /*
         * forceFill, because `gateway_charge_id` is deliberately NOT fillable
         * (ADR-027 §TASK-139): only GatewayPaymentService's conditional
         * UPDATE may claim one, so that a charge id can never be set by mass
         * assignment. A test that used create() would silently produce an
         * order with no charge id — which is not the order being described.
         */
        $order->forceFill(['gateway_charge_id' => 'ch_test_'.$number])->save();

        return $order->refresh();
    }

    public function test_it_closes_the_sale_the_gateway_could_not(): void
    {
        $order = $this->stuckOrder();

        $this->artisan('payments:retry-stuck-confirmations')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_it_points_the_referral_at_the_products_journey_as_it_stands_today(): void
    {
        /*
         * The repair itself. The referral was snapshotted with Medical
         * Package because that is what the product resolved to at the time;
         * the product now carries ขายตรง, and a referral that has not moved
         * has no history on the old journey to lose.
         */
        $order = $this->stuckOrder();
        $referral = Referral::withoutGlobalScope(TenantScope::class)->findOrFail($order->referral_id);

        $this->artisan('payments:retry-stuck-confirmations')->assertSuccessful();

        $journey = PipelineTemplate::withoutGlobalScopes()
            ->findOrFail($referral->refresh()->pipeline_template_id);

        $this->assertNull($journey->company_id, 'ต้องเป็นเส้นทางกลางของสินค้ากลาง');
        $this->assertSame(PipelineTemplate::KEY_DIRECT_SALE_DEFAULT, $journey->key);
    }

    public function test_it_refuses_to_rewrite_a_journey_the_customer_has_already_walked(): void
    {
        /*
         * THE LINE. This referral has been to an appointment. Re-snapshotting
         * it onto ขายตรง would silently delete a step a person performed, and
         * would then confirm a payment on a journey the customer was never
         * actually on. Reported, and left for a human.
         */
        $order = $this->stuckOrder(PipelineStage::WaitingAppointment);
        $referral = Referral::withoutGlobalScope(TenantScope::class)->findOrFail($order->referral_id);
        $before = $referral->pipeline_template_id;

        $this->artisan('payments:retry-stuck-confirmations')
            ->expectsOutputToContain('ต้องให้คนตัดสินใจ')
            ->assertSuccessful();

        $this->assertSame($before, $referral->refresh()->pipeline_template_id);
        $this->assertNotSame(OrderStatus::Paid, $order->refresh()->status);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        // An operator's first move on production, and it has to be true —
        // this one writes to money records when it is not a dry run.
        $order = $this->stuckOrder();
        $referral = Referral::withoutGlobalScope(TenantScope::class)->findOrFail($order->referral_id);
        $before = $referral->pipeline_template_id;

        $this->artisan('payments:retry-stuck-confirmations --dry-run')->assertSuccessful();

        $this->assertSame($before, $referral->refresh()->pipeline_template_id);
        $this->assertNotSame(OrderStatus::Paid, $order->refresh()->status);
    }

    public function test_it_leaves_an_order_nobody_has_paid_alone(): void
    {
        // No charge id means no money arrived. Confirming one of these would
        // be inventing a payment.
        $order = $this->stuckOrder();
        $order->forceFill(['gateway_charge_id' => null])->save();

        $this->artisan('payments:retry-stuck-confirmations')->assertSuccessful();

        $this->assertNotSame(OrderStatus::Paid, $order->refresh()->status);
    }

    public function test_it_can_be_pointed_at_one_order(): void
    {
        // What an operator reaches for first: fix the one that was reported,
        // look at the rest afterwards.
        $reported = $this->stuckOrder();
        $other = $this->stuckOrder(PipelineStage::CompleteRegistered, 'ORD-A207RWYF');

        $this->artisan('payments:retry-stuck-confirmations --order=ORD-MWJTV2QV')->assertSuccessful();

        $this->assertSame(OrderStatus::Paid, $reported->refresh()->status);
        $this->assertNotSame(OrderStatus::Paid, $other->refresh()->status);
    }
}
