<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionRateType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Order;
use App\Models\PipelineStageLog;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-11 (human: "มีลูกค้าชำระเงินมาจำนวนมากแล้วทำไมไม่ได้ค่าคอม").
 *
 * A missing commission is a NEGATIVE — no row, nothing for a screen to show,
 * and CommissionService is deliberately silent about it (a missing rate must
 * not block a sale from being recorded). So the only honest way to answer the
 * question is a tool that walks the same gates the Service walks and names
 * the first one that closed.
 *
 * These tests build one paid order per cause and assert the command says the
 * right thing about it. A diagnostic that names the WRONG cause is worse than
 * none: somebody spends an afternoon configuring a rate that was never the
 * problem.
 */
class ExplainCommissionGapTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_names_the_missing_rate_when_there_is_no_rule(): void
    {
        $order = $this->paidOrder(withRule: false, withCert: true, enteredPaymentStage: true);

        $this->artisan('commissions:explain', ['--order' => $order->order_number])
            ->expectsOutputToContain('ไม่มีอัตราค่าคอมของบริษัทนี้')
            ->assertSuccessful();
    }

    public function test_it_names_the_certification_when_the_agent_has_none(): void
    {
        // BR-1. Checked before the rate, because an uncertified agent cannot
        // be paid however well the rates are configured.
        $order = $this->paidOrder(withRule: true, withCert: false, enteredPaymentStage: true);

        $this->artisan('commissions:explain', ['--order' => $order->order_number])
            ->expectsOutputToContain('ยังไม่ผ่าน cert tier')
            ->assertSuccessful();
    }

    public function test_it_names_the_cause_nobody_guesses(): void
    {
        /*
         * THE ONE THIS COMMAND EXISTS FOR.
         *
         * Commission fires on the TRANSITION into ชำระเงินแล้ว, not on the
         * state. A referral already sitting at that stage when its order is
         * confirmed is marked paid, is given its voucher, and never triggers
         * a commission — because confirmPayment() skips the advance to stop a
         * re-confirm paying twice.
         *
         * Nothing is logged, because nothing went wrong: the Service was
         * never called. Rates and certifications can both be perfect and the
         * money still never appears, which is exactly the shape of "ลูกค้า
         * ชำระเงินมาจำนวนมากแล้วทำไมไม่ได้ค่าคอม".
         */
        $order = $this->paidOrder(withRule: true, withCert: true, enteredPaymentStage: false);

        $this->artisan('commissions:explain', ['--order' => $order->order_number])
            ->expectsOutputToContain('ไม่เคยมีการเปลี่ยนสถานะเข้า')
            ->assertSuccessful();
    }

    public function test_an_order_that_was_paid_correctly_is_not_reported_as_a_problem(): void
    {
        // The control. Without it every test above would also pass against a
        // command that called everything broken.
        $order = $this->paidOrder(withRule: true, withCert: true, enteredPaymentStage: true);

        $this->ledgerFor($order);

        $this->artisan('commissions:explain', ['--order' => $order->order_number])
            ->expectsOutputToContain('ได้ค่าคอมเรียบร้อย: 1 รายการ')
            ->assertSuccessful();
    }

    public function test_it_flags_a_rate_that_belongs_to_another_company(): void
    {
        /*
         * CommissionRule carries TenantScope, and resolveCommissionRule()
         * relies on it rather than filtering by company itself. With an
         * authenticated admin that is correct. On a GATEWAY-confirmed payment
         * there is no authenticated user, the scope becomes a no-op, and the
         * same lookup can return a rule belonging to somebody else — paying a
         * rate nobody at this company set, into a row that can never be
         * edited.
         *
         * The command runs in that same no-user condition, so it can see the
         * difference and say so.
         */
        $order = $this->paidOrder(withRule: false, withCert: true, enteredPaymentStage: true);

        // Another company's company-wide default, and nothing for this one.
        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => Company::factory()->create()->id,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 500,
            'effective_from' => now()->subDay(),
        ]);

        $this->artisan('commissions:explain', ['--order' => $order->order_number])
            ->expectsOutputToContain('ไม่มีอัตราค่าคอมของบริษัทนี้')
            ->assertSuccessful();
    }

    public function test_it_changes_nothing(): void
    {
        // A tool for answering "what happened" is run on a live system by
        // somebody already worried. It must be impossible for it to make
        // things worse.
        $order = $this->paidOrder(withRule: true, withCert: true, enteredPaymentStage: false);

        $before = $order->fresh()->toArray();

        $this->artisan('commissions:explain', ['--order' => $order->order_number])->assertSuccessful();

        $this->assertSame($before, $order->fresh()->toArray());
        $this->assertSame(0, CommissionLedger::withoutGlobalScopes()->count());
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function paidOrder(bool $withRule, bool $withCert, bool $enteredPaymentStage): Order
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        if ($withCert) {
            UserCertification::create([
                'company_id' => $company->id,
                'user_id' => $agent->id,
                'cert_tier_id' => $tier->id,
                'passed_at' => now(),
            ]);
        }

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);

        if ($withRule) {
            CommissionRule::factory()->create([
                'company_id' => $company->id,
                'cert_tier_id' => $tier->id,
                'product_id' => $product->id,
                'rate_type' => CommissionRateType::Percentage,
                'rate_value' => 300,
            ]);
        }

        $referral = Referral::create([
            'company_id' => $company->id,
            'client_id' => Client::factory()->create([
                'company_id' => $company->id,
                'referring_agent_id' => $agent->id,
            ])->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => null,
            'preferred_time' => null,
            'current_stage' => PipelineStage::CompletePayment,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);

        if ($enteredPaymentStage) {
            PipelineStageLog::create([
                'company_id' => $company->id,
                'referral_id' => $referral->id,
                'from_stage' => PipelineStage::CompleteRegistered,
                'to_stage' => PipelineStage::CompletePayment,
                'changed_by_user_id' => $agent->id,
                'changed_at' => now(),
            ]);
        }

        $order = Order::factory()->create(['referral_id' => $referral->id]);
        $order->forceFill(['status' => OrderStatus::Paid->value, 'paid_at' => now()])->save();

        return $order->fresh();
    }

    private function ledgerFor(Order $order): void
    {
        CommissionLedger::withoutGlobalScopes()->create([
            'company_id' => $order->company_id,
            'referral_id' => $order->referral_id,
            'agent_id' => $order->agent_id,
            'product_id' => $order->product_id,
            'sale_price_satang_at_time' => 890000,
            'rate_type_applied' => CommissionRateType::Percentage,
            'rate_applied' => 300,
            'amount_satang' => 26700,
            'earned_via' => CommissionEarnedVia::Direct,
            'payment_status' => PaymentStatus::Pending,
        ]);
    }
}
