<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Commission\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 2026-09-14 — THE TRIPWIRE BETWEEN WHAT PAYS AND WHAT THE SCREEN SAYS.
 *
 * `resolveCommissionRule()` short-circuits: it stops at the first rung that
 * matches, because it runs on every confirmed sale and a product with its own
 * rate should cost one query, not three. `commissionRuleLadder()` deliberately
 * does NOT short-circuit — the admin screen has to show which layers lost, not
 * just which one won.
 *
 * Two methods, one question. That is a mirror, and this codebase has already
 * been bitten once by a mirror that drifted: a rate belonging to Thai Life was
 * resolved, displayed and PAID as AIA's, because the screen's copy of the
 * lookup and the money's copy of the lookup had stopped agreeing
 * (CrossCompanyRateIsolationTest is the other half of that story).
 *
 * So the two share `liveCommissionRules()` — the company scope, the date
 * window and the tie-break, which is the part that was actually wrong that day
 * — and this file asserts the rest: for every shape of configuration, the
 * ladder's `winner` IS the rule `resolveCommissionRule()` hands the ledger.
 *
 * If this file ever fails, do not "fix" the screen. The screen is about to
 * start explaining a payout that is not happening.
 */
class RateLadderAgreementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string, 1: list<string>, 2: bool}>
     */
    public static function configurations(): array
    {
        // [name, which rungs are filled, does the product have a category]
        return [
            'nothing set at all' => ['nothing', [], true],
            'company only' => ['company', ['company'], true],
            'category only' => ['category', ['category'], true],
            'product only' => ['product', ['product'], true],
            'company and category' => ['company+category', ['company', 'category'], true],
            'company and product' => ['company+product', ['company', 'product'], true],
            'category and product' => ['category+product', ['category', 'product'], true],
            'all three' => ['all', ['company', 'category', 'product'], true],
            // The rung that does not merely sit empty but does not APPLY: a
            // product with no category skips the middle of the ladder, and
            // that is the case a naive ladder gets wrong by matching some
            // other company's category row.
            'no category, company only' => ['uncategorised-company', ['company'], false],
            'no category, category rate exists anyway' => ['uncategorised-orphan', ['company', 'category'], false],
        ];
    }

    /**
     * @param  list<string>  $rungs
     */
    #[DataProvider('configurations')]
    public function test_the_agent_ladder_winner_is_what_gets_paid(string $name, array $rungs, bool $hasCategory): void
    {
        [$company, $product] = $this->world($rungs, $hasCategory, leader: false);

        $service = app(CommissionService::class);
        $ladder = $service->commissionRuleLadder($product, (int) $company->id);
        $paid = $service->resolveCommissionRule($product, (int) $company->id);

        $this->assertSame(
            $paid?->id,
            $ladder['winner'] === null ? null : $ladder[$ladder['winner']]?->id,
            "ladder and payout disagree for [{$name}]",
        );
    }

    /**
     * @param  list<string>  $rungs
     */
    #[DataProvider('configurations')]
    public function test_the_leader_ladder_winner_is_what_gets_paid(string $name, array $rungs, bool $hasCategory): void
    {
        [$company, $product] = $this->world($rungs, $hasCategory, leader: true);

        $service = app(CommissionService::class);
        $ladder = $service->overrideRuleLadder($product, (int) $company->id);
        $paid = $service->resolveLeaderRule($product, (int) $company->id);

        $this->assertSame(
            $paid?->id,
            $ladder['winner'] === null ? null : $ladder[$ladder['winner']]?->id,
            "leader ladder and payout disagree for [{$name}]",
        );
    }

    public function test_the_category_rung_is_null_when_the_product_has_no_category(): void
    {
        /*
         * NULL means two different things on this rung and the screen needs
         * both: "nobody has set a category rate" and "this product is not in
         * any category, so the rung cannot apply". They render differently —
         * an empty cell you can click versus a dash you cannot — and the
         * distinction is also why an uncategorised product must never pick up
         * a category row belonging to something else.
         */
        [$company, $product] = $this->world(['company', 'category'], hasCategory: false, leader: false);

        $ladder = app(CommissionService::class)->commissionRuleLadder($product, (int) $company->id);

        $this->assertNull($ladder['category']);
        $this->assertSame('company', $ladder['winner']);
    }

    public function test_a_rate_outside_its_dates_is_on_no_rung(): void
    {
        // The ladder answers "today", exactly as the payout does. A rate that
        // ended yesterday must not appear as the winner on a screen somebody
        // is about to trust.
        $company = Company::factory()->create();
        $product = Product::factory()->create(['company_id' => $company->id, 'category_id' => null]);

        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 900,
            'effective_from' => now()->subYear(),
            'effective_to' => now()->subMonth(),
        ]);

        $ladder = app(CommissionService::class)->commissionRuleLadder($product, (int) $company->id);

        $this->assertNull($ladder['product']);
        $this->assertNull($ladder['winner']);
    }

    /**
     * @param  list<string>  $rungs
     * @return array{0: Company, 1: Product}
     */
    private function world(array $rungs, bool $hasCategory, bool $leader): array
    {
        $company = Company::factory()->create();
        $category = ProductCategory::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => $hasCategory ? $category->id : null,
        ]);

        $scopes = [
            'company' => ['product_id' => null, 'product_category_id' => null],
            'category' => ['product_id' => null, 'product_category_id' => $category->id],
            'product' => ['product_id' => $product->id, 'product_category_id' => null],
        ];

        foreach ($rungs as $rung) {
            $row = [
                'company_id' => $company->id,
                'rate_type' => CommissionRateType::Percentage,
                'rate_value' => 300,
                'effective_from' => now()->subDay(),
            ] + $scopes[$rung];

            $leader
                ? CommissionOverrideRule::withoutGlobalScopes()->create($row + ['manager_cert_tier_id' => null])
                : CommissionRule::withoutGlobalScopes()->create($row);
        }

        return [$company, $product];
    }
}
