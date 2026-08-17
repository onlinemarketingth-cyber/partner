<?php

namespace Tests\Feature\Catalog;

use App\Enums\PipelineStage;
use App\Enums\PromotionStatus;
use App\Enums\RewardType;
use App\Models\Brand;
use App\Models\CertTier;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\PipelineTemplate;
use App\Models\PipelineTemplateStage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPricePromotion;
use App\Models\ProductRecommendationPin;
use App\Models\ProductShareLink;
use App\Models\Referral;
use App\Models\RewardItem;
use App\Models\StorefrontBanner;
use App\Models\User;
use App\Models\UserCertification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-156 §3 — "ปิดการใช้งาน ซ่อนทุกที่" (human decision, 2026-08-10).
 *
 * A deactivated thing is hidden everywhere an Agent could DISCOVER or CHOOSE
 * it, and still resolvable where an existing record already points at it.
 *
 * Both halves are load-bearing and both are tested here. The second half is
 * the reason this is per-endpoint filtering and not a Global Scope on Product:
 * CommissionLedgerResource / OrderResource / ReferralResource read the LIVE
 * `product` relation for the product's NAME (TASK-047 snapshotted the price
 * onto the ledger, not the name), so a blanket filter would blank the product
 * on an agent's own paid commission rows — data loss wearing a policy's
 * clothes. test_a_commission_ledger_row_still_names_a_deactivated_product()
 * is what stops someone "simplifying" this into a Global Scope later.
 *
 * No factories exist for StorefrontBanner / ProductRecommendationPin /
 * ProductPricePromotion / RewardItem, so those are built with Model::create()
 * — the same style CertTierTargetModeTest uses where a factory is missing.
 */
class InactiveVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User, 2: User} [company, agent, companyAdmin] */
    private function makeTenant(): array
    {
        $company = Company::factory()->create();

        return [
            $company,
            User::factory()->agent()->create(['company_id' => $company->id]),
            User::factory()->companyAdmin()->create(['company_id' => $company->id]),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row) => $row['id'], $rows);
    }

    // ── Brands ─────────────────────────────────────────────────────────

    public function test_an_agent_does_not_see_an_inactive_brand_in_the_list(): void
    {
        [$company, $agent] = $this->makeTenant();
        $active = Brand::factory()->for($company)->create(['is_active' => true]);
        $inactive = Brand::factory()->for($company)->create(['is_active' => false]);

        $ids = $this->ids($this->actingAs($agent)->getJson('/api/v1/brands')->assertOk()->json('data'));

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_a_company_admin_still_sees_an_inactive_brand(): void
    {
        [$company, , $admin] = $this->makeTenant();
        $inactive = Brand::factory()->for($company)->create(['is_active' => false]);

        $ids = $this->ids($this->actingAs($admin)->getJson('/api/v1/brands')->assertOk()->json('data'));

        $this->assertContains($inactive->id, $ids);
    }

    // ── Categories ─────────────────────────────────────────────────────

    public function test_an_agent_does_not_see_an_inactive_category_in_the_list(): void
    {
        [$company, $agent] = $this->makeTenant();
        $active = ProductCategory::factory()->for($company)->create(['is_active' => true]);
        $inactive = ProductCategory::factory()->for($company)->create(['is_active' => false]);

        $ids = $this->ids($this->actingAs($agent)->getJson('/api/v1/product-categories')->assertOk()->json('data'));

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_a_company_admin_still_sees_an_inactive_category(): void
    {
        [$company, , $admin] = $this->makeTenant();
        $inactive = ProductCategory::factory()->for($company)->create(['is_active' => false]);

        $ids = $this->ids($this->actingAs($admin)->getJson('/api/v1/product-categories')->assertOk()->json('data'));

        $this->assertContains($inactive->id, $ids);
    }

    // ── Products: list ─────────────────────────────────────────────────

    public function test_an_agent_does_not_see_an_inactive_product_in_the_list(): void
    {
        [$company, $agent] = $this->makeTenant();
        $active = Product::factory()->for($company)->create(['is_active' => true]);
        $inactive = Product::factory()->for($company)->create(['is_active' => false]);

        $ids = $this->ids($this->actingAs($agent)->getJson('/api/v1/products')->assertOk()->json('data'));

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_an_agent_cannot_ask_for_inactive_products_back_with_the_query_parameter(): void
    {
        // For an Agent `is_active` is a RULE, not the opt-in filter it used
        // to be — otherwise the fix would be one query string away from
        // being no fix at all.
        [$company, $agent] = $this->makeTenant();
        $inactive = Product::factory()->for($company)->create(['is_active' => false]);

        $ids = $this->ids($this->actingAs($agent)->getJson('/api/v1/products?is_active=0')->assertOk()->json('data'));

        $this->assertNotContains($inactive->id, $ids);
        $this->assertSame([], $ids);
    }

    public function test_a_company_admin_still_sees_inactive_products_and_can_still_filter_for_them(): void
    {
        // The Admin's opt-in `?is_active=` behaviour is preserved exactly:
        // finding what they switched off is why they are exempt.
        [$company, , $admin] = $this->makeTenant();
        $active = Product::factory()->for($company)->create(['is_active' => true]);
        $inactive = Product::factory()->for($company)->create(['is_active' => false]);

        $unfiltered = $this->ids($this->actingAs($admin)->getJson('/api/v1/products')->assertOk()->json('data'));
        $this->assertContains($active->id, $unfiltered);
        $this->assertContains($inactive->id, $unfiltered);

        $onlyInactive = $this->ids($this->actingAs($admin)->getJson('/api/v1/products?is_active=0')->assertOk()->json('data'));
        $this->assertSame([$inactive->id], $onlyInactive);
    }

    // ── Products: show ─────────────────────────────────────────────────

    public function test_an_agent_gets_404_for_an_inactive_product_by_id(): void
    {
        // 404, not 403 (CLAUDE.md §5.5, same as TASK-155's draft Sections):
        // "this exists but is not for you" is itself the withheld thing, and
        // ids are sequential so the list filter alone is not mitigation.
        [$company, $agent] = $this->makeTenant();
        $inactive = Product::factory()->for($company)->create(['is_active' => false]);

        $this->actingAs($agent)->getJson("/api/v1/products/{$inactive->id}")->assertNotFound();
    }

    public function test_an_agent_can_still_read_an_active_product_by_id(): void
    {
        [$company, $agent] = $this->makeTenant();
        $active = Product::factory()->for($company)->create(['is_active' => true]);

        $this->actingAs($agent)->getJson("/api/v1/products/{$active->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $active->id);
    }

    public function test_a_company_admin_can_still_read_an_inactive_product_by_id(): void
    {
        [$company, , $admin] = $this->makeTenant();
        $inactive = Product::factory()->for($company)->create(['is_active' => false]);

        $this->actingAs($admin)->getJson("/api/v1/products/{$inactive->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $inactive->id);
    }

    // ── Storefront banners ─────────────────────────────────────────────

    private function makeBanner(Company $company, Product $product, bool $isActive): StorefrontBanner
    {
        return StorefrontBanner::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'image_path' => 'banners/test.jpg',
            'title' => 'แบนเนอร์ทดสอบ',
            'sort_order' => 0,
            'is_active' => $isActive,
        ]);
    }

    public function test_an_agent_does_not_see_an_inactive_storefront_banner(): void
    {
        [$company, $agent] = $this->makeTenant();
        $product = Product::factory()->for($company)->create();
        $active = $this->makeBanner($company, $product, true);
        $inactive = $this->makeBanner($company, $product, false);

        $ids = $this->ids($this->actingAs($agent)->getJson('/api/v1/storefront-banners')->assertOk()->json('data'));

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_a_company_admin_still_sees_an_inactive_storefront_banner(): void
    {
        [$company, , $admin] = $this->makeTenant();
        $product = Product::factory()->for($company)->create();
        $inactive = $this->makeBanner($company, $product, false);

        $ids = $this->ids($this->actingAs($admin)->getJson('/api/v1/storefront-banners')->assertOk()->json('data'));

        $this->assertContains($inactive->id, $ids);
    }

    // ── Recommendation pins ────────────────────────────────────────────

    public function test_an_agent_does_not_see_an_inactive_recommendation_pin(): void
    {
        [$company, $agent] = $this->makeTenant();
        $active = ProductRecommendationPin::create([
            'company_id' => $company->id,
            'product_id' => Product::factory()->for($company)->create()->id,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $inactive = ProductRecommendationPin::create([
            'company_id' => $company->id,
            'product_id' => Product::factory()->for($company)->create()->id,
            'sort_order' => 1,
            'is_active' => false,
        ]);

        $ids = $this->ids($this->actingAs($agent)->getJson('/api/v1/product-recommendation-pins')->assertOk()->json('data'));

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_a_company_admin_still_sees_an_inactive_recommendation_pin(): void
    {
        [$company, , $admin] = $this->makeTenant();
        $inactive = ProductRecommendationPin::create([
            'company_id' => $company->id,
            'product_id' => Product::factory()->for($company)->create()->id,
            'sort_order' => 0,
            'is_active' => false,
        ]);

        $ids = $this->ids($this->actingAs($admin)->getJson('/api/v1/product-recommendation-pins')->assertOk()->json('data'));

        $this->assertContains($inactive->id, $ids);
    }

    // ── Price promotions ───────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePromotion(Company $company, Product $product, array $overrides = []): ProductPricePromotion
    {
        return ProductPricePromotion::create(array_merge([
            'company_id' => $company->id,
            'product_id' => $product->id,
            // BR-3 — integer satang. Arbitrary test value, not a BR-7 claim.
            'discounted_price_satang' => 750000,
            'status' => PromotionStatus::Active,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
        ], $overrides));
    }

    public function test_an_agent_only_sees_currently_active_price_promotions(): void
    {
        // The sharpest item in the audit: this list filtered on product_id
        // and nothing else, so an Agent could read UNANNOUNCED FUTURE price
        // cuts and expired promo pricing.
        [$company, $agent] = $this->makeTenant();
        $product = Product::factory()->for($company)->create();

        $live = $this->makePromotion($company, $product);
        $draft = $this->makePromotion($company, $product, ['status' => PromotionStatus::Draft]);
        $future = $this->makePromotion($company, $product, [
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeeks(2)->toDateString(),
        ]);
        $expired = $this->makePromotion($company, $product, [
            'starts_at' => now()->subWeeks(2)->toDateString(),
            'ends_at' => now()->subWeek()->toDateString(),
        ]);
        $forcedEnded = $this->makePromotion($company, $product, ['status' => PromotionStatus::Ended]);
        $openEnded = $this->makePromotion($company, $product, ['ends_at' => null]);

        $ids = $this->ids($this->actingAs($agent)->getJson('/api/v1/product-price-promotions')->assertOk()->json('data'));

        $this->assertContains($live->id, $ids);
        $this->assertContains($openEnded->id, $ids);
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($future->id, $ids);
        $this->assertNotContains($expired->id, $ids);
        $this->assertNotContains($forcedEnded->id, $ids);
    }

    public function test_a_company_admin_still_sees_every_price_promotion(): void
    {
        [$company, , $admin] = $this->makeTenant();
        $product = Product::factory()->for($company)->create();
        $draft = $this->makePromotion($company, $product, ['status' => PromotionStatus::Draft]);
        $future = $this->makePromotion($company, $product, [
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeeks(2)->toDateString(),
        ]);

        $ids = $this->ids($this->actingAs($admin)->getJson('/api/v1/product-price-promotions')->assertOk()->json('data'));

        $this->assertContains($draft->id, $ids);
        $this->assertContains($future->id, $ids);
    }

    public function test_the_currently_active_scope_and_the_php_predicate_agree_row_for_row(): void
    {
        // The anti-drift guard. scopeCurrentlyActive() (SQL, used by the list
        // endpoint) and isCurrentlyActive() (PHP, used by ProductPricingService
        // and the Resource) are two expressions of one rule; a second
        // implementation that can drift is the exact bug class TASK-156
        // exists to close, so this asserts they answer identically over every
        // shape of window and status.
        [$company] = $this->makeTenant();
        $product = Product::factory()->for($company)->create();

        $this->makePromotion($company, $product);
        $this->makePromotion($company, $product, ['status' => PromotionStatus::Draft]);
        $this->makePromotion($company, $product, ['status' => PromotionStatus::Ended]);
        $this->makePromotion($company, $product, ['ends_at' => null]);
        $this->makePromotion($company, $product, [
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->toDateString(),
        ]);
        $this->makePromotion($company, $product, [
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeeks(2)->toDateString(),
        ]);
        $this->makePromotion($company, $product, [
            'starts_at' => now()->subWeeks(2)->toDateString(),
            'ends_at' => now()->subWeek()->toDateString(),
        ]);

        $all = ProductPricePromotion::withoutGlobalScopes()->get();
        $this->assertCount(7, $all);

        $viaPredicate = $all->filter->isCurrentlyActive()->pluck('id')->sort()->values()->all();
        $viaScope = ProductPricePromotion::withoutGlobalScopes()
            ->currentlyActive()->pluck('id')->sort()->values()->all();

        $this->assertSame($viaPredicate, $viaScope);
        // Not vacuous: the fixture above contains both matches and misses.
        $this->assertNotEmpty($viaScope);
        $this->assertNotCount(7, $viaScope);
    }

    // ── Reward items ───────────────────────────────────────────────────

    private function makeRewardItem(Company $company, bool $isActive): RewardItem
    {
        return RewardItem::create([
            'company_id' => $company->id,
            'name' => 'ของรางวัลทดสอบ',
            'description' => null,
            'cost_points' => 10,
            'stock_quantity' => null,
            'is_active' => $isActive,
            'reward_type' => RewardType::Physical,
        ]);
    }

    public function test_an_agent_does_not_see_an_inactive_reward_item(): void
    {
        [$company, $agent] = $this->makeTenant();
        $active = $this->makeRewardItem($company, true);
        $inactive = $this->makeRewardItem($company, false);

        $ids = $this->ids($this->actingAs($agent)->getJson('/api/v1/reward-items')->assertOk()->json('data'));

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_a_company_admin_still_sees_an_inactive_reward_item(): void
    {
        [$company, , $admin] = $this->makeTenant();
        $inactive = $this->makeRewardItem($company, false);

        $ids = $this->ids($this->actingAs($admin)->getJson('/api/v1/reward-items')->assertOk()->json('data'));

        $this->assertContains($inactive->id, $ids);
    }

    // ── Public checkout from a share token ─────────────────────────────

    public function test_public_checkout_refuses_a_deactivated_product(): void
    {
        // A share link outlives the catalogue decision that produced it, so
        // hiding the product from browse is not enough — the one route that
        // turns a link into a payable order has to refuse too, or
        // "deactivated" still takes the customer's money.
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);
        UserCertification::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        $template = PipelineTemplate::create([
            'company_id' => $company->id,
            'key' => PipelineTemplate::KEY_DIRECT_SALE_DEFAULT,
            'name' => 'Direct sale default',
            'is_system' => true,
        ]);
        foreach ([PipelineStage::CompleteRegistered, PipelineStage::CompletePayment] as $position => $stage) {
            PipelineTemplateStage::create([
                'company_id' => $company->id,
                'pipeline_template_id' => $template->id,
                'stage' => $stage,
                'position' => $position,
            ]);
        }

        $product = Product::factory()->create([
            'company_id' => $company->id,
            'price_satang' => 890000,
            'pipeline_template_id' => $template->id,
            'is_active' => false,
        ]);
        $link = ProductShareLink::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
        ]);

        $this->postJson("/api/v1/public/product-shares/{$link->token}/checkout", [
            'name' => 'สมชาย ใจดี',
            'phone' => '0812345678',
            'consent' => true,
        ])
            /*
             * 404, NOT the 422 this test originally asserted.
             *
             * ag-lead follow-up to TASK-156 §3.1 moved the inactive-product
             * check up into PublicProductShareController::resolveUsableLink(),
             * so it now fires for the WHOLE link — showcase, media, sales
             * material and checkout — rather than only for the one route that
             * takes money. Checkout is refused strictly earlier than before.
             *
             * The answer is deliberately byte-identical to the revoked and
             * expired cases beside it: a customer holding a stale link learns
             * the LINK is dead, not that the company discontinued a product.
             * A 422 here would have made this endpoint an oracle for another
             * company's catalogue state, which is the thing §6 of that task
             * was guarding against.
             *
             * ProductShareCheckoutService keeps its own null-refusal (→ 422)
             * as defence in depth for any caller that reaches the Service
             * without passing through this controller. Both still refuse; only
             * the code a customer sees changed.
             */
            ->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('referrals', 0);
    }

    // ── The boundary: history still resolves ───────────────────────────

    public function test_a_commission_ledger_row_still_names_a_deactivated_product(): void
    {
        /*
         * THE REASON THIS IS PER-ENDPOINT FILTERING AND NOT A GLOBAL SCOPE.
         *
         * CommissionLedgerResource reads the LIVE `product` relation for the
         * name — TASK-047 snapshotted the sale PRICE and the promotion onto
         * the ledger, not the name. A Global Scope on Product (or a blanket
         * filter) would therefore render `product: null` on an agent's own
         * paid commission rows for every product the company has since
         * discontinued: a money record describing a package no report can
         * name, which is worse than the leak it fixes and looks like data
         * loss rather than a policy (TASK-156 §3 boundary).
         *
         * If this test ever fails, the fix is NOT to relax the assertion.
         */
        [$company, $agent] = $this->makeTenant();
        $product = Product::factory()->for($company)->create(['is_active' => true, 'name' => 'แพ็กเกจสุขภาพเลิกขายแล้ว']);

        $ledger = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'referral_id' => Referral::factory()->create([
                'company_id' => $company->id,
                'agent_id' => $agent->id,
                'product_id' => $product->id,
            ])->id,
        ]);

        $product->update(['is_active' => false]);

        // The product is now invisible to this agent everywhere it could be
        // chosen...
        $this->actingAs($agent)->getJson("/api/v1/products/{$product->id}")->assertNotFound();
        $this->assertNotContains(
            $product->id,
            $this->ids($this->actingAs($agent)->getJson('/api/v1/products')->assertOk()->json('data'))
        );

        // ...and still named on the money record that already happened.
        $response = $this->actingAs($agent)->getJson('/api/v1/commission-ledger')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $ledger->id);

        $this->assertNotNull($row, 'the agent must still see their own commission row');
        $this->assertSame($product->id, $row['product']['id']);
        $this->assertSame('แพ็กเกจสุขภาพเลิกขายแล้ว', $row['product']['name']);
    }
}
