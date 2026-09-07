<?php

namespace Tests\Feature\Catalog;

use App\Enums\CommissionPlanType;
use App\Models\Brand;
use App\Models\Company;
use App\Models\CompanyProductSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Scopes\SharedOrTenantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-256 / ADR-040 — the admin catalogue answers about the company in the
 * HEADER, not about the person reading it.
 *
 * A shared product has no single price and no single on-sale state; it has one
 * per company. Every resolved field on ProductResource therefore needs a
 * company to resolve against, and the obvious choice — the viewer's own —
 * breaks for the only role allowed to edit these. A Super Admin belongs to no
 * company, so before this every shared product reported the central price and
 * "not for sale", whichever company they had picked in the switcher. The
 * switcher already travels as `?company_id=`; that is the context.
 *
 * The security half matters just as much: `?company_id=` is read ONLY for a
 * Super Admin. For anyone else it is ignored, exactly as CompanyScopeFilter
 * has always ignored it — otherwise a Company Admin could ask what a rival
 * charges by editing a query string.
 */
class SharedProductAdminContextTest extends TestCase
{
    use RefreshDatabase;

    private Company $thaiLife;

    private Company $aia;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->thaiLife = Company::factory()->create(['name' => 'Thai Life']);
        $this->aia = Company::factory()->create(['name' => 'AIA']);

        $this->product = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => null,
            'brand_id' => Brand::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => null, 'name' => 'Genesenn (กลาง)', 'is_active' => true])->id,
            'category_id' => ProductCategory::withoutGlobalScope(SharedOrTenantScope::class)
                ->create(['company_id' => null, 'name' => 'Anti Aging (กลาง)', 'is_active' => true, 'sort_order' => 0])->id,
            'name' => 'Vital Blueprint V5',
            'price_satang' => 890000,
            'commission_plan_type' => CommissionPlanType::Unilevel,
            'is_active' => true,
        ]);

        // Thai Life sells it at a discount; AIA has the right but keeps it off.
        $this->setting($this->thaiLife, 790000, true);
        $this->setting($this->aia, null, false);
    }

    private function setting(Company $company, ?int $price, bool $active): void
    {
        CompanyProductSetting::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'product_id' => $this->product->id,
            'price_satang' => $price,
            'is_active' => $active,
        ]);
    }

    private function row(array $payload): array
    {
        return $payload['data'][0];
    }

    // ── Super Admin: the header company is the answer ────────────────

    public function test_a_scoped_super_admin_sees_that_companys_price_and_switch(): void
    {
        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/products?company_id={$this->thaiLife->id}")
            ->assertOk();

        $row = $this->row($response->json());

        // The central price is still there — a Super Admin editing the central
        // number has to be able to see the central number.
        $this->assertSame(890000, $row['price_satang']);
        $this->assertSame(790000, $row['effective_price_satang']);
        $this->assertTrue($row['is_sellable_here']);
        $this->assertTrue($row['is_shared']);
    }

    public function test_an_inherited_price_is_distinguishable_from_a_deliberate_one(): void
    {
        /*
         * Thai Life typed 7,900; AIA typed nothing. Both would answer the
         * question "what does this cost" — only `own_price_satang` says which
         * of the two numbers will move on its own the next time the central
         * price is edited. The screen labels the second "(ราคากลาง)", and it
         * cannot do that from the effective price alone: a company that
         * deliberately matched the centre looks identical.
         */
        $superAdmin = User::factory()->superAdmin()->create();

        $thaiLife = $this->row($this->actingAs($superAdmin)
            ->getJson("/api/v1/products?company_id={$this->thaiLife->id}")->assertOk()->json());
        $this->assertSame(790000, $thaiLife['own_price_satang']);

        $aia = $this->row($this->actingAs($superAdmin)
            ->getJson("/api/v1/products?company_id={$this->aia->id}")->assertOk()->json());
        $this->assertNull($aia['own_price_satang']);
        $this->assertSame(890000, $aia['effective_price_satang']);
    }

    public function test_a_deliberate_zero_is_a_price_not_an_unset_one(): void
    {
        // BR-3 / the `?? not ?:` rule in ProductPricingService, asserted where
        // a UI can see it: a free onboarding item must not read as inherited.
        CompanyProductSetting::withoutGlobalScopes()
            ->where('company_id', $this->aia->id)
            ->where('product_id', $this->product->id)
            ->update(['price_satang' => 0]);

        $row = $this->row($this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/products?company_id={$this->aia->id}")->assertOk()->json());

        $this->assertSame(0, $row['own_price_satang']);
        $this->assertSame(0, $row['effective_price_satang']);
    }

    public function test_a_company_owned_product_never_reports_an_own_price(): void
    {
        // There is nothing else that could own a price for a row that already
        // belongs to one company — null here means "not applicable", and the
        // screen shows no per-company controls at all.
        $own = Product::withoutGlobalScope(SharedOrTenantScope::class)->create([
            'company_id' => $this->thaiLife->id,
            'brand_id' => $this->product->brand_id,
            'category_id' => $this->product->category_id,
            'name' => 'Thai Life Only',
            'price_satang' => 100000,
            'is_active' => true,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/products/{$own->id}?company_id={$this->thaiLife->id}")
            ->assertOk()
            ->assertJsonPath('data.is_shared', false)
            ->assertJsonPath('data.own_price_satang', null)
            ->assertJsonPath('data.effective_price_satang', 100000);
    }

    public function test_switching_the_header_to_the_other_company_changes_both_answers(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $aia = $this->row($this->actingAs($superAdmin)
            ->getJson("/api/v1/products?company_id={$this->aia->id}")
            ->assertOk()
            ->json());

        // AIA set no price, so it inherits the central one — and has not
        // switched the product on, so it is not selling it.
        $this->assertSame(890000, $aia['effective_price_satang']);
        $this->assertFalse($aia['is_sellable_here']);
    }

    public function test_all_companies_mode_reports_the_central_price_and_no_sale(): void
    {
        /*
         * "ทุกบริษัท" genuinely has no company context, so there is no honest
         * per-company answer to give. It reports the central price (the row's
         * own number) and false — not because AIA said no, but because the
         * question was not asked about anybody. The screen must therefore hide
         * the per-company controls in this mode rather than render a state
         * nobody set.
         */
        $row = $this->row($this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/v1/products')
            ->assertOk()
            ->json());

        $this->assertSame(890000, $row['effective_price_satang']);
        $this->assertFalse($row['is_sellable_here']);
    }

    // ── Everyone else: their own company, and only that ──────────────

    public function test_a_company_admin_sees_their_own_company_without_asking(): void
    {
        $row = $this->row($this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $this->thaiLife->id]))
            ->getJson('/api/v1/products')
            ->assertOk()
            ->json());

        $this->assertSame(790000, $row['effective_price_satang']);
        $this->assertTrue($row['is_sellable_here']);
    }

    public function test_a_company_admin_cannot_ask_what_another_company_charges(): void
    {
        /*
         * The one that would be a leak. `?company_id=` is a Super Admin's
         * header switcher, not a lookup API — a Company Admin who types
         * another company's id gets their OWN answer back, unchanged, exactly
         * as CompanyScopeFilter::apply() has always ignored it for them.
         */
        $row = $this->row($this->actingAs(User::factory()->companyAdmin()->create(['company_id' => $this->aia->id]))
            ->getJson("/api/v1/products?company_id={$this->thaiLife->id}")
            ->assertOk()
            ->json());

        $this->assertSame(890000, $row['effective_price_satang']);
        $this->assertFalse($row['is_sellable_here']);
    }

    public function test_an_agent_only_ever_sees_a_product_their_own_company_sells(): void
    {
        // Belt and braces over the index clause: even with the query string of
        // a company that IS selling it, an AIA agent gets an empty list.
        $this->actingAs(User::factory()->agent()->create(['company_id' => $this->aia->id]))
            ->getJson("/api/v1/products?company_id={$this->thaiLife->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_show_endpoint_answers_for_the_header_company_too(): void
    {
        // The edit screen opens from the same header scope as the list; a
        // detail view that reverted to the central price would look like the
        // list had been wrong.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson("/api/v1/products/{$this->product->id}?company_id={$this->thaiLife->id}")
            ->assertOk()
            ->assertJsonPath('data.effective_price_satang', 790000)
            ->assertJsonPath('data.is_sellable_here', true);
    }
}
