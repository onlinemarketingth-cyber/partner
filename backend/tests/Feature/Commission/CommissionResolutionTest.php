<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionOverrideMode;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /commission-resolution — "สินค้าตัวนี้จ่ายเท่าไหร่ และมาจากชั้นไหน".
 *
 * The owner's complaint was that three overlapping layers made it impossible
 * to see what a setting actually did ("ทำได้ไม่ชัดเจน"), and the fix he chose
 * was a table of products x layers that you can also edit from. This endpoint
 * is what fills it.
 *
 * The tests below are about the two things that make such a table worth having
 * rather than dangerous:
 *
 *   1. IT REPORTS THE LOSERS, WITH THEIR NUMBERS. "5% instead of 8%" is the
 *      sentence an admin is trying to form when a rate seems to do nothing,
 *      and a table that showed only the winner cannot form it.
 *   2. IT COMES FROM THE CODE THAT PAYS. Every figure here is produced by
 *      CommissionService's own ladder and rounding — not a second
 *      implementation that can drift, which is precisely how a Thai Life rate
 *      once got paid at AIA.
 */
class CommissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_every_rung_and_which_one_wins(): void
    {
        $world = $this->world();

        // Three live agent rates on one product: 3% company, 5% category,
        // 8% product. The product rung wins and the other two are reported
        // anyway, because "why didn't my category rate apply" is the question
        // this table exists to answer.
        $this->rate($world, 'company', 300);
        $this->rate($world, 'category', 500);
        $this->rate($world, 'product', 800);

        $row = $this->rows($world)[0];

        $this->assertSame('product', $row['agent']['winner']);
        $this->assertSame(80000, $row['agent']['amount_satang'], '8% of 10,000 baht');
        $this->assertSame(50000, $row['agent']['category']['amount_satang'], 'the loser still says what it would have paid');
        $this->assertSame(30000, $row['agent']['company']['amount_satang']);
    }

    public function test_an_empty_rung_is_null_and_so_is_a_rung_that_cannot_apply(): void
    {
        /*
         * Two different nulls, and the screen renders them differently — an
         * empty cell you may click versus a dash you may not. Collapsing them
         * would invite an admin to set a category rate on a product that is
         * in no category, which resolves to nothing and looks like a bug in
         * the system rather than in the configuration.
         */
        $world = $this->world(withCategory: false);
        $this->rate($world, 'company', 300);

        $row = $this->rows($world)[0];

        $this->assertNull($row['category'], 'the product itself has no category');
        $this->assertNull($row['agent']['category'], 'so the category rung cannot apply');
        $this->assertNull($row['agent']['product'], 'and nothing is set at the product rung');
        $this->assertSame('company', $row['agent']['winner']);
    }

    public function test_a_product_nobody_can_be_paid_on_is_reported_as_such(): void
    {
        // No rate anywhere. The sale still closes (deliberately — a config gap
        // never blocks a deal), so this row is the ONLY place anybody finds
        // out before an agent asks where their money went.
        $world = $this->world();

        $row = $this->rows($world)[0];

        $this->assertNull($row['agent']['winner']);
        $this->assertNull($row['agent']['amount_satang']);
    }

    public function test_the_leader_base_follows_the_mode(): void
    {
        /*
         * THE 33x CASE. Under DeductFromCommission the leader's percentage
         * applies to the SELLER'S COMMISSION, not to the sale — 2% of 300 is
         * 6 baht, where 2% of 10,000 is 200. A table that always divided by
         * the sale would misreport one of the two modes by a factor of
         * thirty-three, on a screen built to end exactly that confusion.
         */
        $world = $this->world();
        $world['company']->update(['commission_override_mode' => CommissionOverrideMode::DeductFromCommission]);
        $this->rate($world, 'company', 300);
        $this->leaderRate($world, 'company', 200);

        $row = $this->rows($world)[0];

        $this->assertSame(30000, $row['agent']['amount_satang'], 'seller: 3% of the sale');
        $this->assertSame(600, $row['leader']['amount_satang'], 'leader: 2% of the seller commission, not of the sale');
        $this->assertSame('deduct_from_commission', $row['leader']['override_mode']);
        $this->assertSame('company', $row['leader']['override_mode_source']);
    }

    public function test_a_rate_with_its_own_mode_says_the_mode_came_from_the_rate(): void
    {
        // An inherited row and a row that chose the same value are not the
        // same fact — one moves when the company changes its mind. The screen
        // needs the difference to label them honestly.
        $world = $this->world();
        $this->rate($world, 'company', 300);
        $this->leaderRate($world, 'company', 200, mode: CommissionOverrideMode::Additive);

        $row = $this->rows($world)[0];

        $this->assertSame('additive', $row['leader']['override_mode']);
        $this->assertSame('rule', $row['leader']['override_mode_source']);
    }

    public function test_the_ceiling_is_the_worst_product_divided_by_the_chain(): void
    {
        /*
         * The same arithmetic OverrideDeductionGuard refuses with, computed
         * once and sent, so the screen can show the maximum BEFORE anybody
         * types instead of predicting it with its own copy and eventually
         * offering a number the server rejects.
         */
        $world = $this->world();
        $this->rate($world, 'company', 300);
        $this->chainOfDepth($world, 2);

        $payload = $this->payload($world);

        // 3% of 10,000 = 300 baht of pool, over two levels = 150 each.
        $this->assertSame(2, $payload['deepest_manager_chain']);
        $this->assertSame(15000, $payload['max_override_per_level_satang']);
    }

    public function test_no_hierarchy_means_no_ceiling_rather_than_a_ceiling_of_zero(): void
    {
        // Zero would read as "you may not set any leader rate at all", which
        // is the opposite of what an empty hierarchy means.
        $world = $this->world();
        $this->rate($world, 'company', 300);

        $payload = $this->payload($world);

        $this->assertSame(0, $payload['deepest_manager_chain']);
        $this->assertNull($payload['max_override_per_level_satang']);
    }

    public function test_a_product_this_company_does_not_sell_is_listed_and_flagged(): void
    {
        /*
         * 2026-09-14 — this assertion used to be the opposite, and the reason
         * it flipped is the screen, not the arithmetic: the table absorbed the
         * per-product edit list, and the switch that REOPENS a closed product
         * lives on its row. Excluding the row removed the only way out of the
         * state the row exists to report. The flag is what keeps the two
         * meanings apart.
         */
        $world = $this->world();
        $closed = Product::factory()->create(['company_id' => $world['company']->id, 'is_active' => false]);

        $rows = collect($this->rows($world));

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->firstWhere('product_id', $world['product']->id)['is_sellable']);
        $this->assertFalse($rows->firstWhere('product_id', $closed->id)['is_sellable']);
    }

    public function test_a_closed_product_does_not_tighten_the_override_ceiling(): void
    {
        /*
         * The ceiling mirrors OverrideDeductionGuard, which only ever looks at
         * products the company can sell. A closed product with a smaller
         * commission must not lower the maximum the screen offers, or the
         * screen refuses a rate the server would happily take.
         */
        $world = $this->world();
        $this->rate($world, 'company', 300);
        $this->chainOfDepth($world, 2);

        $ceilingWithoutIt = $this->payload($world)['max_override_per_level_satang'];

        Product::factory()->create([
            'company_id' => $world['company']->id,
            'is_active' => false,
            'price_satang' => 100,
        ]);

        $this->assertSame($ceilingWithoutIt, $this->payload($world)['max_override_per_level_satang']);
    }

    public function test_an_agent_may_not_read_it(): void
    {
        $world = $this->world();

        $this->actingAs(User::factory()->agent()->create(['company_id' => $world['company']->id]))
            ->getJson('/api/v1/commission-resolution')
            ->assertForbidden();
    }

    public function test_a_company_admin_gets_their_own_company_whatever_they_ask_for(): void
    {
        // BR-6 — the narrowing parameter must never widen anybody's scope.
        $mine = $this->world();
        $theirs = $this->world();
        $this->rate($theirs, 'company', 900);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $mine['company']->id]);

        $this->actingAs($admin)
            ->getJson("/api/v1/commission-resolution?company_id={$theirs['company']->id}")
            ->assertOk()
            ->assertJsonPath('data.company_id', $mine['company']->id);
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /** @return array{company: Company, product: Product, category: ?ProductCategory} */
    private function world(bool $withCategory = true): array
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel]);
        $category = $withCategory ? ProductCategory::factory()->create(['company_id' => $company->id]) : null;
        $product = Product::factory()->create([
            'company_id' => $company->id,
            'category_id' => $category?->id,
            'price_satang' => 1000000,
        ]);

        return compact('company', 'product', 'category');
    }

    /** @param array{company: Company, product: Product, category: ?ProductCategory} $world */
    private function rate(array $world, string $scope, int $rateValue): void
    {
        CommissionRule::withoutGlobalScopes()->create($this->scopeRow($world, $scope) + [
            'company_id' => $world['company']->id,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $rateValue,
            'effective_from' => now()->subDay(),
        ]);
    }

    /** @param array{company: Company, product: Product, category: ?ProductCategory} $world */
    private function leaderRate(array $world, string $scope, int $rateValue, ?CommissionOverrideMode $mode = null): void
    {
        CommissionOverrideRule::withoutGlobalScopes()->create($this->scopeRow($world, $scope) + [
            'company_id' => $world['company']->id,
            'manager_cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $rateValue,
            'override_mode' => $mode,
            'effective_from' => now()->subDay(),
        ]);
    }

    /**
     * @param  array{company: Company, product: Product, category: ?ProductCategory}  $world
     * @return array<string, int|null>
     */
    private function scopeRow(array $world, string $scope): array
    {
        return match ($scope) {
            'product' => ['product_id' => $world['product']->id, 'product_category_id' => null],
            'category' => ['product_id' => null, 'product_category_id' => $world['category']?->id],
            default => ['product_id' => null, 'product_category_id' => null],
        };
    }

    /** @param array{company: Company} $world */
    private function chainOfDepth(array $world, int $depth): void
    {
        $managerId = null;
        for ($i = 0; $i < $depth; $i++) {
            $managerId = User::factory()->agent()->create([
                'company_id' => $world['company']->id,
                'manager_id' => $managerId,
            ])->id;
        }

        User::factory()->agent()->create(['company_id' => $world['company']->id, 'manager_id' => $managerId]);
    }

    /**
     * @param  array{company: Company}  $world
     * @return array<string, mixed>
     */
    private function payload(array $world): array
    {
        return $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/commission-resolution?company_id={$world['company']->id}")
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array{company: Company}  $world
     * @return list<array<string, mixed>>
     */
    private function rows(array $world): array
    {
        return $this->payload($world)['products'];
    }
}
