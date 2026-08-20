<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-215 — `commission:audit-unpaid-closures`.
 *
 * The case that matters most is the third test: a sale whose only ledger
 * row is a PROMOTION BONUS must still be reported. That is the exact shape
 * found in live data during UAT-016, and the shape most likely to be
 * dismissed as "it paid something, so it's fine".
 */
class AuditUnpaidClosuresCommandTest extends TestCase
{
    use RefreshDatabase;

    private function closedReferral(Company $company, ?User $agent = null): Referral
    {
        $agent ??= User::factory()->agent()->create(['company_id' => $company->id]);
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::firstOrCreate([
            'company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id,
        ], ['passed_at' => now()]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);

        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id,
            'product_id' => $product->id, 'branch' => 'Silom', 'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompletePayment, 'meeting_number' => null, 'submitted_at' => now(),
        ]);

        // The audit reads the pipeline LOG, not current_stage — a referral
        // that moved on to a post-sale stage must still count as closed.
        DB::table('pipeline_stage_logs')->insert([
            'company_id' => $company->id,
            'referral_id' => $referral->id,
            'from_stage' => PipelineStage::Finish1stDoctorMeeting->value,
            'to_stage' => PipelineStage::CompletePayment->value,
            'changed_by_user_id' => $agent->id,
            'changed_at' => now(),
            'created_at' => now(),
        ]);

        return $referral;
    }

    private function ledgerRow(Referral $referral, CommissionEarnedVia $via, int $amount = 5000): void
    {
        CommissionLedger::create([
            'company_id' => $referral->company_id,
            'agent_id' => $referral->agent_id,
            'referral_id' => $referral->id,
            'product_id' => $referral->product_id,
            'cert_tier_id_at_time' => CertTier::where('key', 'basic')->value('id'),
            'rate_type_applied' => CommissionRateType::Percentage,
            'rate_applied' => 500,
            'amount_satang' => $amount,
            'earned_via' => $via,
        ]);
    }

    public function test_it_reports_a_closed_sale_with_no_ledger_row_at_all(): void
    {
        $this->closedReferral(Company::factory()->create());

        $this->artisan('commission:audit-unpaid-closures')
            ->expectsOutputToContain('never paid the selling agent')
            ->assertExitCode(0);
    }

    public function test_a_sale_with_a_direct_row_is_not_reported(): void
    {
        $referral = $this->closedReferral(Company::factory()->create());
        $this->ledgerRow($referral, CommissionEarnedVia::Direct);

        $this->artisan('commission:audit-unpaid-closures')
            ->expectsOutputToContain('every one produced a commission row')
            ->assertExitCode(0);
    }

    /**
     * THE case from live data. A promotion bonus moved money, so the sale
     * looks paid at a glance — but the agent's actual commission was never
     * booked and never will be (BR-4).
     */
    public function test_a_sale_whose_only_row_is_a_promotion_bonus_is_still_reported(): void
    {
        $referral = $this->closedReferral(Company::factory()->create());
        $this->ledgerRow($referral, CommissionEarnedVia::PromotionBonus, 89000);

        $this->artisan('commission:audit-unpaid-closures')
            ->expectsOutputToContain('never paid the selling agent')
            ->assertExitCode(0);
    }

    /** An upline's override is not the seller's commission either. */
    public function test_a_sale_whose_only_row_is_an_upline_override_is_still_reported(): void
    {
        $referral = $this->closedReferral(Company::factory()->create());
        $this->ledgerRow($referral, CommissionEarnedVia::Override);

        $this->artisan('commission:audit-unpaid-closures')
            ->expectsOutputToContain('never paid the selling agent')
            ->assertExitCode(0);
    }

    public function test_it_can_be_limited_to_one_company(): void
    {
        $wanted = Company::factory()->create();
        $other = Company::factory()->create();
        $this->closedReferral($other); // unpaid, but out of scope

        $paid = $this->closedReferral($wanted);
        $this->ledgerRow($paid, CommissionEarnedVia::Direct);

        $this->artisan('commission:audit-unpaid-closures', ['--company' => $wanted->id])
            ->expectsOutputToContain('every one produced a commission row')
            ->assertExitCode(0);
    }
}
