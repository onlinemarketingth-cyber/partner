<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionRateType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PipelineStage;
use App\Models\AuditLog;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * SECURITY AUDIT 2026-08-21 — V12 (BR-4 enforced) and V15 (refund exists).
 *
 * These two are one subject, not two. BR-4 forbids editing or deleting a
 * commission row; before this pair of changes it forbade that with a
 * comment, and it also left no legitimate way to undo a sale — so the only
 * available remedy for a reversed bank transfer was hand-editing
 * production, i.e. doing the exact thing the rule forbids. Enforcing the
 * rule without providing the alternative would have made the system
 * strictly worse to operate; providing the alternative without enforcing
 * the rule would have left the old shortcut in place.
 */
class CommissionReversalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: Referral, 3: Order} */
    private function paidSale(): array
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 890000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'cert_tier_id' => $tier->id,
            'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
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

        $order = Order::factory()->awaitingVerification()->create(['referral_id' => $referral->id]);

        $this->actingAs($this->paymentConfirmer($company))
            ->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertOk();

        return [$company, $agent, $referral, $order->fresh()];
    }

    // -----------------------------------------------------------------
    // V12 — the ledger is immutable, and now says so in code.
    // -----------------------------------------------------------------

    public function test_a_commission_entrys_amount_cannot_be_edited(): void
    {
        $entry = CommissionLedger::factory()->create(['amount_satang' => 26700]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/immutable/');

        $entry->update(['amount_satang' => 999999]);
    }

    public function test_a_commission_entry_cannot_be_deleted(): void
    {
        $entry = CommissionLedger::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/never deleted/');

        $entry->delete();
    }

    public function test_marking_paid_is_still_allowed_because_that_is_the_one_exception(): void
    {
        // The guard is an allowlist; if this breaks, payouts break.
        $entry = CommissionLedger::factory()->create(['payment_status' => PaymentStatus::Pending]);

        $entry->update(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $this->assertSame(PaymentStatus::Paid, $entry->fresh()->payment_status);
    }

    // -----------------------------------------------------------------
    // V15 — the refund that makes the rule above livable.
    // -----------------------------------------------------------------

    public function test_a_super_admin_refund_writes_a_reversing_entry_and_the_pair_nets_to_zero(): void
    {
        [, $agent, $referral, $order] = $this->paidSale();
        $original = CommissionLedger::withoutGlobalScopes()->where('referral_id', $referral->id)->sole();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/orders/{$order->id}/refund", ['reason' => 'ลูกค้าโอนกลับ ธนาคารตีคืน'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        $reversal = CommissionLedger::withoutGlobalScopes()
            ->where('reverses_commission_ledger_id', $original->id)
            ->sole();

        $this->assertSame(-26700, $reversal->amount_satang);
        $this->assertSame(CommissionEarnedVia::Reversal, $reversal->earned_via);
        $this->assertSame($agent->id, $reversal->agent_id);

        // THE ASSERTION THAT MATTERS: every balance in this application is
        // SUM(amount_satang), so if the pair nets to zero, every one of
        // them is already correct with no query changed anywhere.
        $this->assertSame(
            0,
            (int) CommissionLedger::withoutGlobalScopes()->where('agent_id', $agent->id)->sum('amount_satang'),
        );

        // The original is untouched — that is the whole point of BR-4.
        $this->assertSame(26700, $original->fresh()->amount_satang);
    }

    public function test_the_refund_is_audit_logged_with_who_why_and_how_much(): void
    {
        [, , , $order] = $this->paidSale();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/orders/{$order->id}/refund", ['reason' => 'ยืนยันผิดรายการ'])
            ->assertOk();

        $log = AuditLog::where('action', 'order.refunded')->sole();

        $this->assertSame($superAdmin->id, $log->actor_user_id);
        $this->assertSame('ยืนยันผิดรายการ', $log->new_values['reason']);
        $this->assertSame(1, $log->new_values['commission_entries_reversed']);
        $this->assertSame(-26700, $log->new_values['commission_satang_reversed']);
    }

    public function test_a_reason_is_required(): void
    {
        // A money movement with no stated cause is a hole in the audit
        // trail at exactly the point somebody will later need to read it.
        [, , , $order] = $this->paidSale();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/orders/{$order->id}/refund", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_a_company_admin_cannot_refund(): void
    {
        [$company, , , $order] = $this->paidSale();

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $company->id]))
            ->postJson("/api/v1/orders/{$order->id}/refund", ['reason' => 'อยากลอง'])
            ->assertForbidden();

        $this->assertDatabaseCount('commission_ledger', 1);
    }

    public function test_an_agent_cannot_refund_their_own_sale(): void
    {
        [, $agent, , $order] = $this->paidSale();

        $this->actingAs($agent)
            ->postJson("/api/v1/orders/{$order->id}/refund", ['reason' => 'ขอคืนเอง'])
            ->assertForbidden();
    }

    public function test_refunding_twice_is_refused_rather_than_doubling_the_reversal(): void
    {
        // The obvious way this feature goes wrong. Refused in the service
        // AND made impossible by a unique index on
        // reverses_commission_ledger_id — the second is what holds when two
        // requests arrive at the same moment.
        [, , , $order] = $this->paidSale();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/orders/{$order->id}/refund", ['reason' => 'ตีคืนจากธนาคาร'])
            ->assertOk();

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/orders/{$order->id}/refund", ['reason' => 'ตีคืนจากธนาคาร'])
            ->assertUnprocessable();

        $this->assertSame(2, CommissionLedger::withoutGlobalScopes()->count());
    }

    public function test_an_unpaid_order_cannot_be_refunded(): void
    {
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
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
        $order = Order::factory()->create(['referral_id' => $referral->id]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/orders/{$order->id}/refund", ['reason' => 'ยังไม่ได้จ่ายเลย'])
            ->assertUnprocessable();
    }
}
