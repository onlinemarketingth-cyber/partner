<?php

namespace Tests\Feature\Commission;

use App\Console\Commands\DispatchDueRenewalCommissions;
use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// TASK-024 (ADR-006, ADR-004 pattern) — time-travel tests via
// Carbon::setTestNow(), mirroring FollowUpReminderTest's style. These
// exercise DispatchDueRenewalCommissions end to end: CommissionService
// stamps next_renewal_date at the direct-sale moment (only when the
// firing rule has a renewal rate), and the command later reads it back.
class RenewalCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function passCert(User $agent, Company $company, CertTier $tier): void
    {
        UserCertification::create(['company_id' => $company->id, 'user_id' => $agent->id, 'cert_tier_id' => $tier->id, 'passed_at' => now()]);
    }

    private function advanceToStage(Referral $referral, User $agent, PipelineStage $target): Referral
    {
        while ($referral->current_stage !== $target) {
            $this->actingAs($agent)->postJson("/api/v1/referrals/{$referral->id}/advance")->assertOk();
            $referral->refresh();
        }

        return $referral;
    }

    /** @return array{0: Company, 1: User, 2: Referral} */
    private function sellOneReferralWithRenewalRule(bool $recurs): array
    {
        $company = Company::factory()->create();
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]); // 10,000 THB
        CommissionRule::factory()->withRenewal(200, $recurs)->create([ // 2% renewal
            'company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id,
            'rate_type' => CommissionRateType::Percentage, 'rate_value' => 300, // 3% direct
        ]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        return [$company, $agent, $referral->refresh()];
    }

    public function test_a_due_renewal_with_recurs_false_fires_exactly_once_and_never_again(): void
    {
        [, $agent, $referral] = $this->sellOneReferralWithRenewalRule(recurs: false);

        $this->assertNotNull($referral->next_renewal_date);
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());

        Carbon::setTestNow(now()->addYear());

        $this->artisan(DispatchDueRenewalCommissions::class)->assertSuccessful();

        $this->assertSame(2, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertDatabaseHas('commission_ledger', [
            'referral_id' => $referral->id, 'agent_id' => $agent->id,
            'earned_via' => CommissionEarnedVia::Renewal->value, 'amount_satang' => 20000, // 2% of 10,000 THB
        ]);
        $this->assertNull($referral->refresh()->next_renewal_date);

        // A year further still — recurs=false means the claim was
        // cleared, so nothing new should ever fire again.
        Carbon::setTestNow(now()->addYear());
        $this->artisan(DispatchDueRenewalCommissions::class)->assertSuccessful();

        $this->assertSame(2, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    public function test_a_due_renewal_with_recurs_true_fires_again_a_year_later(): void
    {
        [, $agent, $referral] = $this->sellOneReferralWithRenewalRule(recurs: true);

        Carbon::setTestNow(now()->addYear());
        $this->artisan(DispatchDueRenewalCommissions::class)->assertSuccessful();

        $this->assertSame(2, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertNotNull($referral->refresh()->next_renewal_date);

        Carbon::setTestNow(now()->addYear());
        $this->artisan(DispatchDueRenewalCommissions::class)->assertSuccessful();

        $this->assertSame(3, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertSame(2, CommissionLedger::where('referral_id', $referral->id)
            ->where('earned_via', CommissionEarnedVia::Renewal->value)->count());
        $this->assertSame($agent->id, CommissionLedger::where('referral_id', $referral->id)
            ->where('earned_via', CommissionEarnedVia::Renewal->value)->latest('id')->first()->agent_id);
    }

    public function test_a_referral_with_no_renewal_rate_configured_never_fires(): void
    {
        $company = Company::factory()->create();
        $basic = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->passCert($agent, $company, $basic);

        $product = Product::factory()->create(['company_id' => $company->id, 'price_satang' => 1000000]);
        // Plain rule, no ->withRenewal() — renewal_rate_type stays null.
        CommissionRule::factory()->create(['company_id' => $company->id, 'cert_tier_id' => $basic->id, 'product_id' => $product->id]);

        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $agent->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $agent->id, 'product_id' => $product->id,
            'branch' => 'Silom', 'preferred_time' => now()->addDay(), 'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->advanceToStage($referral, $agent, PipelineStage::CompletePayment);

        $this->assertNull($referral->refresh()->next_renewal_date);

        Carbon::setTestNow(now()->addYear());
        $this->artisan(DispatchDueRenewalCommissions::class)->assertSuccessful();

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
    }

    public function test_a_not_yet_due_renewal_is_untouched(): void
    {
        [, , $referral] = $this->sellOneReferralWithRenewalRule(recurs: false);

        // Still today — next_renewal_date is ~1 year out, nothing due yet.
        $this->artisan(DispatchDueRenewalCommissions::class)->assertSuccessful();

        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertNotNull($referral->refresh()->next_renewal_date);
    }

    public function test_running_the_command_twice_on_the_same_due_day_does_not_double_fire(): void
    {
        [, , $referral] = $this->sellOneReferralWithRenewalRule(recurs: false);

        Carbon::setTestNow(now()->addYear());

        $this->artisan(DispatchDueRenewalCommissions::class)->assertSuccessful();
        $this->artisan(DispatchDueRenewalCommissions::class)->assertSuccessful();

        $this->assertSame(2, CommissionLedger::where('referral_id', $referral->id)->count());
        $this->assertSame(1, CommissionLedger::where('referral_id', $referral->id)
            ->where('earned_via', CommissionEarnedVia::Renewal->value)->count());
    }
}
