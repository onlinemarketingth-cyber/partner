<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Enums\PipelineStage;
use App\Models\CertTier;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When the certification gate skips somebody, who gets their level?
 *
 * The gate itself is old (ADR-035): a manager who has passed nothing is not
 * paid, and the walk carries on above them. What was never decided is what
 * the next manager up is then paid — the skipped person's level, or the one
 * after it.
 *
 * With one flat rate the question had no observable answer; every level paid
 * the same number. Per-level rates turn it into the difference between 3% and
 * 1% for a real person, into a row BR-4 will not let anybody correct, so it
 * needs a stated answer. Both answers are real plans that real companies run,
 * so it is a company switch (BR-7) whose default is exactly what every
 * existing company does today.
 */
class UnilevelCompressionTest extends TestCase
{
    use RefreshDatabase;

    private function certify(User $agent, Company $company): CertTier
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::firstOrCreate(
            ['user_id' => $agent->id, 'cert_tier_id' => $tier->id],
            ['company_id' => $company->id, 'passed_at' => now()],
        );

        return $tier;
    }

    private function rate(Company $company, int $basisPoints, ?int $level): void
    {
        CommissionOverrideRule::factory()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'level' => $level,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $basisPoints,
        ]);
    }

    private function overrideFor(User $manager): int
    {
        return (int) CommissionLedger::withoutGlobalScopes()
            ->where('agent_id', $manager->id)
            ->where('earned_via', CommissionEarnedVia::Override->value)
            ->sum('amount_satang');
    }

    /**
     * A chain where the MIDDLE manager has no certification, so the gate
     * skips them: seller -> m1 (certified) -> m2 (NOT) -> m3 (certified).
     *
     * @return array{0: User, 1: User, 2: User, 3: User}
     */
    private function chainWithAGapInTheMiddle(Company $company): array
    {
        $m3 = User::factory()->agent()->create(['company_id' => $company->id]);
        $this->certify($m3, $company);

        // Deliberately uncertified — this is the person the gate skips.
        $m2 = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $m3->id]);

        $m1 = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $m2->id]);
        $this->certify($m1, $company);

        $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $m1->id]);

        return [$seller, $m1, $m2, $m3];
    }

    private function sell(Company $company, User $seller): void
    {
        $tier = $this->certify($seller, $company);
        $product = Product::factory()->for($company)->create(['price_satang' => 1_000_000]);
        CommissionRule::factory()->create([
            'company_id' => $company->id, 'cert_tier_id' => $tier->id, 'product_id' => $product->id,
        ]);
        $client = Client::factory()->create(['company_id' => $company->id, 'referring_agent_id' => $seller->id]);
        $referral = Referral::create([
            'company_id' => $company->id, 'client_id' => $client->id, 'agent_id' => $seller->id,
            'product_id' => $product->id, 'branch' => 'Silom', 'preferred_time' => now()->addDay(),
            'current_stage' => PipelineStage::CompleteRegistered, 'meeting_number' => null, 'submitted_at' => now(),
        ]);
        $this->closeSale($referral, $seller);
    }

    private function company(bool $compression): Company
    {
        $company = Company::factory()->create([
            'commission_plan_type' => CommissionPlanType::Unilevel->value,
            'commission_override_mode' => CommissionOverrideMode::Additive->value,
            'override_compression' => $compression,
        ]);
        $this->rate($company, 500, 1); // 5%
        $this->rate($company, 300, 2); // 3%
        $this->rate($company, 100, 3); // 1%

        return $company;
    }

    public function test_by_default_a_skipped_manager_still_spends_their_level(): void
    {
        // Today's behaviour, and the default. m2 is skipped by the gate, and
        // m3 — who is three hops up — is paid level 3's 1%.
        $company = $this->company(compression: false);
        [$seller, $m1, $m2, $m3] = $this->chainWithAGapInTheMiddle($company);

        $this->sell($company, $seller);

        $this->assertSame(50_000, $this->overrideFor($m1)); // level 1 · 5%
        $this->assertSame(0, $this->overrideFor($m2));      // skipped by the gate
        $this->assertSame(10_000, $this->overrideFor($m3)); // level 3 · 1%
    }

    public function test_with_compression_on_the_next_manager_inherits_the_skipped_level(): void
    {
        $company = $this->company(compression: true);
        [$seller, $m1, $m2, $m3] = $this->chainWithAGapInTheMiddle($company);

        $this->sell($company, $seller);

        $this->assertSame(50_000, $this->overrideFor($m1)); // level 1 · 5%
        $this->assertSame(0, $this->overrideFor($m2));      // still skipped
        $this->assertSame(30_000, $this->overrideFor($m3)); // level 2 · 3%, inherited
    }

    public function test_compression_changes_nothing_when_nobody_is_skipped(): void
    {
        // The switch must be invisible on a fully-certified chain, or it is
        // not compression, it is a different rate table.
        foreach ([false, true] as $compression) {
            $company = $this->company($compression);
            $m2 = User::factory()->agent()->create(['company_id' => $company->id]);
            $this->certify($m2, $company);
            $m1 = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $m2->id]);
            $this->certify($m1, $company);
            $seller = User::factory()->agent()->create(['company_id' => $company->id, 'manager_id' => $m1->id]);

            $this->sell($company, $seller);

            $this->assertSame(50_000, $this->overrideFor($m1), 'level 1');
            $this->assertSame(30_000, $this->overrideFor($m2), 'level 2');
        }
    }

    public function test_the_depth_cap_counts_paid_levels_when_compressing(): void
    {
        // "Pay two levels" has to mean two people paid, or a company with an
        // uncertified manager in the middle silently pays one.
        $company = $this->company(compression: true);
        $company->update(['max_override_depth' => 2]);
        [$seller, $m1, $m2, $m3] = $this->chainWithAGapInTheMiddle($company);

        $this->sell($company, $seller);

        $this->assertSame(50_000, $this->overrideFor($m1));
        $this->assertSame(0, $this->overrideFor($m2));
        $this->assertSame(30_000, $this->overrideFor($m3)); // the second PAID level
    }

    public function test_the_switch_is_saved_and_audited(): void
    {
        $company = $this->company(compression: false);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/commission-settings', [
                'company_id' => $company->id,
                'override_compression' => true,
            ])
            ->assertOk();

        $this->assertTrue((bool) $company->fresh()->override_compression);
        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'action' => 'commission_override_compression.updated',
        ]);

        // And the switch reads back, so the screen shows its true position
        // rather than defaulting to off over a company that turned it on.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/commission-settings?company_id={$company->id}")
            ->assertOk()
            ->assertJsonPath('data.override_compression', true);
    }
}
