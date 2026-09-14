<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionRateType;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /commission-rate-impact — "บันทึกแล้วจะเกิดอะไรขึ้น" (owner's ข้อเสนอ 3).
 *
 * The rate form already refuses invalid input. What it cannot refuse, and what
 * actually costs money here, are VALID rates that do something other than what
 * the admin pictured. Every test below is one of those:
 *
 *   · a category rate reaching products they forgot were in that category;
 *   · a company default that changes nothing because everything overrides it;
 *   · a rate that reaches no product at all;
 *   · an edit that appears to be blocked by itself.
 *
 * None of them is an error. The only honest intervention is to show the
 * arithmetic while the ledger is still empty — afterwards BR-4 means nobody
 * may correct it.
 */
class CommissionRateImpactTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_names_every_product_a_category_rate_would_change(): void
    {
        // The mistake this exists to catch: "I set the หมวดอาหารเสริม rate for
        // Collagen" and three other products quietly move with it.
        $world = $this->world(productCount: 3);
        $this->agentRate($world, null, null, 300);

        $data = $this->preview($world, ['kind' => 'agent', 'product_category_id' => $world['category']->id, 'rate_value' => 500]);

        $this->assertSame(3, $data['changed_count']);
        $this->assertSame(30000, $data['changed'][0]['before_satang']);
        $this->assertSame(50000, $data['changed'][0]['after_satang']);
        $this->assertFalse($data['reaches_nothing']);
    }

    public function test_it_reports_products_a_narrower_rate_is_shielding(): void
    {
        /*
         * THE "ทำไมตั้งแล้วไม่เปลี่ยน" CASE, caught before the save instead of
         * after. Raising the company default does nothing for a product that
         * carries its own rate — and saying so beforehand is the difference
         * between a configuration decision and a support ticket.
         */
        $world = $this->world(productCount: 2);
        $this->agentRate($world, null, null, 300);
        $this->agentRate($world, $world['products'][0]->id, null, 800);

        $data = $this->preview($world, ['kind' => 'agent', 'rate_value' => 400]);

        $this->assertSame(1, $data['blocked_count']);
        $this->assertSame('product', $data['blocked'][0]['blocked_by']);
        $this->assertSame(1, $data['changed_count'], 'the other product still moves');
    }

    public function test_a_rate_that_reaches_nothing_says_so(): void
    {
        // Not an error — a category may legitimately be configured before its
        // products exist — but almost never what was meant, and nothing else
        // on the screen would mention it.
        $world = $this->world(productCount: 1);
        $empty = ProductCategory::factory()->create(['company_id' => $world['company']->id]);

        $data = $this->preview($world, ['kind' => 'agent', 'product_category_id' => $empty->id, 'rate_value' => 500]);

        $this->assertTrue($data['reaches_nothing']);
        $this->assertSame(0, $data['changed_count']);
    }

    public function test_re_saving_the_same_number_is_reported_as_no_change(): void
    {
        // A preview that cried "1 product will change" when nothing moves is a
        // preview people learn to click past.
        $world = $this->world(productCount: 1);
        $this->agentRate($world, null, null, 300);

        $data = $this->preview($world, ['kind' => 'agent', 'rate_value' => 300]);

        $this->assertSame(0, $data['changed_count']);
        $this->assertTrue($data['changed'][0]['unchanged']);
    }

    public function test_an_edited_rule_does_not_block_itself(): void
    {
        /*
         * Without exclude_rule_id, editing a product rate reports that same
         * rate as the thing shielding the product — true of the database and
         * useless to somebody looking at the form that owns it.
         */
        $world = $this->world(productCount: 1);
        $rule = $this->agentRate($world, $world['products'][0]->id, null, 800);

        $blockedByItself = $this->preview($world, [
            'kind' => 'agent',
            'product_id' => $world['products'][0]->id,
            'rate_value' => 900,
        ]);
        // A product-scoped rate is never blocked — nothing is narrower.
        $this->assertSame(0, $blockedByItself['blocked_count']);

        // The real case: a company-wide edit while a product rate exists, with
        // that product rate being the row under edit.
        $companyRule = $this->agentRate($world, null, null, 300);
        $withExclusion = $this->preview($world, [
            'kind' => 'agent',
            'rate_value' => 400,
            'exclude_rule_id' => $rule->id,
        ]);

        $this->assertSame(0, $withExclusion['blocked_count'], 'the excluded row must not shield anything');
        $this->assertNotNull($companyRule->id);
    }

    public function test_the_leader_preview_follows_the_deduction_mode(): void
    {
        /*
         * THE 33x CASE AGAIN, at the last moment it can still be caught. 2% of
         * a 10,000 sale is 200; 2% of the seller's 300 commission is 6. A
         * preview that used the wrong base would be confidently wrong about
         * the exact number the mode selector was built to explain.
         */
        $world = $this->world(productCount: 1);
        $this->agentRate($world, null, null, 300);

        $onSale = $this->preview($world, [
            'kind' => 'leader',
            'rate_value' => 200,
            'override_mode' => 'deduct_from_sale',
        ]);
        $onCommission = $this->preview($world, [
            'kind' => 'leader',
            'rate_value' => 200,
            'override_mode' => 'deduct_from_commission',
        ]);

        $this->assertSame(20000, $onSale['changed'][0]['after_satang']);
        $this->assertSame(600, $onCommission['changed'][0]['after_satang']);
    }

    public function test_it_writes_nothing(): void
    {
        // The whole value of a preview is that it is safe to run. This asserts
        // the property rather than trusting the absence of a write path.
        $world = $this->world(productCount: 1);

        $this->preview($world, ['kind' => 'agent', 'rate_value' => 500]);

        $this->assertSame(0, CommissionRule::withoutGlobalScopes()->count());
    }

    public function test_a_company_admin_cannot_reach_it(): void
    {
        // Same gate as writing a rate: a preview useful only to somebody about
        // to save should not be granted to roles that cannot save.
        $world = $this->world(productCount: 1);

        $this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $world['company']->id]))
            ->postJson('/api/v1/commission-rate-impact', ['kind' => 'agent', 'rate_type' => 'percentage', 'rate_value' => 500])
            ->assertForbidden();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /** @return array{company: Company, category: ProductCategory, products: list<Product>} */
    private function world(int $productCount): array
    {
        $company = Company::factory()->create();
        $category = ProductCategory::factory()->create(['company_id' => $company->id]);
        $products = [];

        for ($i = 0; $i < $productCount; $i++) {
            $products[] = Product::factory()->create([
                'company_id' => $company->id,
                'category_id' => $category->id,
                'price_satang' => 1000000,
            ]);
        }

        return compact('company', 'category', 'products');
    }

    /** @param array{company: Company} $world */
    private function agentRate(array $world, ?int $productId, ?int $categoryId, int $rateValue): CommissionRule
    {
        return CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $world['company']->id,
            'product_id' => $productId,
            'product_category_id' => $categoryId,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $rateValue,
            'effective_from' => now()->subDay(),
        ]);
    }

    /**
     * @param  array{company: Company}  $world
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function preview(array $world, array $payload): array
    {
        return $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson('/api/v1/commission-rate-impact', $payload + [
                'company_id' => $world['company']->id,
                'rate_type' => 'percentage',
            ])
            ->assertOk()
            ->json('data');
    }
}
