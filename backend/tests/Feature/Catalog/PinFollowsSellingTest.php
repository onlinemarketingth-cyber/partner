<?php

namespace Tests\Feature\Catalog;

use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\ProductRecommendationPin;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Services\Catalog\ProductRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-09 (human: "หากมีการปิดขาย การปักหมุดสินค้าจะปิดทันทีโดยอัตโนมัติ").
 *
 * A pin is a promise on ONE company's storefront: the product appears first in
 * "แนะนำสำหรับคุณ", ahead of everything the ranking would have chosen.
 * Switching the product off for that company left the pin standing, and the
 * pinned half of ProductRecommendationService::recommended() never consulted
 * `company_product_settings` at all — so an agent kept being shown, in the
 * most prominent row on their home screen, a product their company had
 * stopped selling. Tapping it led to a product they could not sell.
 *
 * ── TWO LOCKS, ON PURPOSE ──
 *
 *   THE PIN IS CLEARED ON WRITE. A stored decision that has become impossible
 *   should stop existing, rather than be filtered out at every place it is
 *   ever read.
 *
 *   THE READ CHECKS ANYWAY. The write-side rule cannot reach a pin created
 *   before it existed, or one made by a direct database edit — and this is
 *   the read every agent performs on every visit to their home screen.
 *
 * ── AND IT ONLY EVER SWITCHES OFF ──
 *
 * Turning selling back on does not restore the pin. Promoting a product to the
 * top of a storefront is a deliberate act; resurrecting a months-old one
 * would be the system making that decision on the admin's behalf.
 */
class PinFollowsSellingTest extends TestCase
{
    use RefreshDatabase;

    private Company $aia;

    private Product $shared;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aia = Company::factory()->create(['name' => 'AIA']);

        $this->shared = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'name' => 'GENESENN Vital Blueprint',
            'price_satang' => 890000,
            'is_active' => true,
            'commission_plan_type' => 'unilevel',
        ]);
    }

    private function sellAt(Company $company, bool $on = true): void
    {
        CompanyProductSetting::withoutGlobalScope(TenantScope::class)->updateOrCreate(
            ['company_id' => $company->id, 'product_id' => $this->shared->id],
            ['is_active' => $on],
        );
    }

    private function pinAt(Company $company): ProductRecommendationPin
    {
        return ProductRecommendationPin::withoutGlobalScope(TenantScope::class)->create([
            'company_id' => $company->id,
            'product_id' => $this->shared->id,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    private function pinIsOn(ProductRecommendationPin $pin): bool
    {
        return (bool) ProductRecommendationPin::withoutGlobalScope(TenantScope::class)
            ->whereKey($pin->id)->value('is_active');
    }

    // ── The write side ───────────────────────────────────────────────

    public function test_closing_the_sale_switches_the_pin_off(): void
    {
        $this->sellAt($this->aia);
        $pin = $this->pinAt($this->aia);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$this->shared->id}/company-settings", [
                'company_id' => $this->aia->id,
                'is_active' => false,
            ])
            ->assertOk();

        $this->assertFalse($this->pinIsOn($pin), 'การปักหมุดต้องถูกปิดตามไปด้วย');
    }

    public function test_re_opening_the_sale_does_not_bring_the_pin_back(): void
    {
        // Promoting a product to the top of a storefront is a deliberate act.
        // Restoring a months-old one would be the system deciding for the admin.
        $this->sellAt($this->aia);
        $pin = $this->pinAt($this->aia);
        $actor = User::factory()->superAdmin()->create();

        $this->actingAs($actor)->putJson("/api/v1/products/{$this->shared->id}/company-settings", [
            'company_id' => $this->aia->id, 'is_active' => false,
        ])->assertOk();

        $this->actingAs($actor)->putJson("/api/v1/products/{$this->shared->id}/company-settings", [
            'company_id' => $this->aia->id, 'is_active' => true,
        ])->assertOk();

        $this->assertFalse($this->pinIsOn($pin));
    }

    public function test_another_companys_pin_is_left_alone(): void
    {
        // BR-6. One company closing its own shop says nothing about another's.
        $thaiLife = Company::factory()->create(['name' => 'Thai Life']);
        $this->sellAt($this->aia);
        $this->sellAt($thaiLife);
        $mine = $this->pinAt($this->aia);
        $theirs = $this->pinAt($thaiLife);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$this->shared->id}/company-settings", [
                'company_id' => $this->aia->id, 'is_active' => false,
            ])->assertOk();

        $this->assertFalse($this->pinIsOn($mine));
        $this->assertTrue($this->pinIsOn($theirs));
    }

    public function test_changing_only_the_price_leaves_the_pin_alone(): void
    {
        // The rule is about SWITCHING OFF, not about touching the row: an
        // admin correcting a price must not silently lose their promotion.
        $this->sellAt($this->aia);
        $pin = $this->pinAt($this->aia);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson("/api/v1/products/{$this->shared->id}/company-settings", [
                'company_id' => $this->aia->id, 'price_satang' => 790000,
            ])->assertOk();

        $this->assertTrue($this->pinIsOn($pin));
    }

    // ── The read side ────────────────────────────────────────────────

    public function test_a_pinned_product_the_company_cannot_sell_is_not_recommended(): void
    {
        /*
         * The second lock. Reached by a pin that predates the write-side rule,
         * or a direct database edit — and this is the read every agent
         * performs on every visit to their home screen.
         */
        $this->sellAt($this->aia, false);
        $this->pinAt($this->aia);

        $agent = User::factory()->agent()->create(['company_id' => $this->aia->id]);
        $recommended = app(ProductRecommendationService::class)->recommended($agent);

        $this->assertTrue($recommended->every(fn ($product) => $product->id !== $this->shared->id));
    }

    public function test_a_pinned_product_the_company_does_sell_is_recommended_first(): void
    {
        // The regression guard: the whole feature still works.
        $this->sellAt($this->aia);
        $this->pinAt($this->aia);

        $agent = User::factory()->agent()->create(['company_id' => $this->aia->id]);
        $recommended = app(ProductRecommendationService::class)->recommended($agent);

        $this->assertSame($this->shared->id, $recommended->first()?->id);
    }
}
