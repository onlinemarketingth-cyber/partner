<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Models\CertTier;
use App\Models\CommissionOverrideRule;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-214 — `commission:collapse-override-tiers`.
 *
 * The command's whole job is to NOT silently pick a winner. These tests
 * pin both halves of that: it collapses on its own only when there is
 * nothing to decide, and it refuses to act (in --dry-run) when the rates
 * genuinely differ.
 */
class CollapseOverrideTiersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function rule(Company $company, int $rateValue, array $overrides = []): CommissionOverrideRule
    {
        return CommissionOverrideRule::factory()->create(array_merge([
            'company_id' => $company->id,
            'manager_cert_tier_id' => CertTier::factory()->create()->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $rateValue,
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => null,
        ], $overrides));
    }

    public function test_it_reports_nothing_to_do_when_no_rows_collide(): void
    {
        $this->rule(Company::factory()->create(), 100);

        $this->artisan('commission:collapse-override-tiers', ['--dry-run' => true])
            ->expectsOutputToContain('No colliding team-leader override rates')
            ->assertExitCode(0);
    }

    /**
     * Three tiers, one rate. There is no business decision here — every
     * possible answer is 1% — so the command must not waste a prompt on it.
     */
    public function test_identical_rates_collapse_without_asking(): void
    {
        $company = Company::factory()->create();
        $oldest = $this->rule($company, 100, ['effective_from' => now()->subYear()->toDateString()]);
        $this->rule($company, 100);
        $this->rule($company, 100);

        $this->artisan('commission:collapse-override-tiers')->assertExitCode(0);

        $remaining = CommissionOverrideRule::withoutGlobalScopes()->get();
        $this->assertCount(1, $remaining);
        // The oldest survives so the effective_from history stays truthful.
        $this->assertSame($oldest->id, $remaining->first()->id);
    }

    /** Differing rates are a human's call — --dry-run must change nothing. */
    public function test_differing_rates_are_flagged_and_left_alone_in_dry_run(): void
    {
        $company = Company::factory()->create();
        $this->rule($company, 100);
        $this->rule($company, 250);

        $this->artisan('commission:collapse-override-tiers', ['--dry-run' => true])
            ->expectsOutputToContain('rates DIFFER')
            ->assertExitCode(0);

        $this->assertCount(2, CommissionOverrideRule::withoutGlobalScopes()->get());
    }

    /**
     * A rate that ended before the next one began is a legitimate history,
     * not an ambiguity. Offering those for deletion would destroy the
     * record of what was paid last quarter.
     */
    public function test_rates_whose_dates_do_not_overlap_are_not_treated_as_a_collision(): void
    {
        $company = Company::factory()->create();
        $this->rule($company, 100, [
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => now()->subMonths(6)->toDateString(),
        ]);
        $this->rule($company, 250, ['effective_from' => now()->subMonths(3)->toDateString()]);

        $this->artisan('commission:collapse-override-tiers', ['--dry-run' => true])
            ->expectsOutputToContain('No colliding team-leader override rates')
            ->assertExitCode(0);

        $this->assertCount(2, CommissionOverrideRule::withoutGlobalScopes()->get());
    }

    /** Different scopes are different rules — never collapsed into each other. */
    public function test_a_product_scoped_rate_never_collides_with_the_company_default(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->create(['company_id' => $company->id]);
        $this->rule($company, 100);
        $this->rule($company, 250, ['product_id' => $product->id]);

        $this->artisan('commission:collapse-override-tiers', ['--dry-run' => true])
            ->expectsOutputToContain('No colliding team-leader override rates')
            ->assertExitCode(0);

        $this->assertCount(2, CommissionOverrideRule::withoutGlobalScopes()->get());
    }
}
