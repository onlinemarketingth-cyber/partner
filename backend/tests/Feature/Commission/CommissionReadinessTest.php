<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionBasis;
use App\Enums\CommissionPlanType;
use App\Enums\CommissionRateType;
use App\Models\CommissionOverrideRule;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\Commission\CommissionReadinessService;
use App\Services\Commission\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-11 (owner): "เรื่องค่าคอมเป็นเรื่องสำคัญ หากยังไม่ได้มีการ setup
 * ค่าคอม ให้แจ้งเตือนในทุกหน้า ... หากมีการ setup ค่าคอมไม่ครบถ้วนที่ไม่
 * สมบูรณ์ให้เตือนผู้ใช้"
 *
 * The endpoint behind that banner. What makes it worth its own file rather
 * than three assertions bolted onto the config-health report's tests is the
 * failure it is built to catch, which no count can see:
 *
 *   A COMPANY WHOSE ONLY COMMISSION RATE EXPIRED LAST MONTH HAS A NON-ZERO
 *   commission_rules COUNT AND PAYS NOBODY.
 *
 * CommissionService::recordForReferral() is silent about it on purpose — a
 * missing rate logs a warning and returns null rather than blocking the sale,
 * so the deal closes, the order is immutable, and nothing on any screen says
 * the agent will not be paid. Everything below is an attempt to make that
 * silence impossible to sit in for a whole month.
 */
class CommissionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/commission-readiness';

    /** A company on the default plan (Unilevel), with one sellable product. */
    private function companyWithProduct(): array
    {
        $company = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel]);
        // brand_id/category_id null on purpose: ProductFactory's defaults each
        // spin up a company of their own, and a stray company would change what
        // "ทุกบริษัท" means in the aggregate tests below.
        $product = Product::factory()->for($company)->create(['category_id' => null, 'brand_id' => null]);

        return [$company, $product];
    }

    /** A live company-wide agent rate — the one every product falls back to. */
    private function companyDefaultRule(Company $company, array $over = []): CommissionRule
    {
        return CommissionRule::factory()->create(array_merge([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 300,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ], $over));
    }

    /** A live company-wide LEADER rate. Without it a Unilevel company is never 'ready'. */
    private function companyDefaultOverrideRule(Company $company): CommissionOverrideRule
    {
        return CommissionOverrideRule::factory()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 150,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);
    }

    // -----------------------------------------------------------------
    // 1. The three states
    // -----------------------------------------------------------------

    /**
     * RED. "ดีลที่ปิดได้จะไม่มีใครได้เงิน" is a literal claim about money, so
     * it is reserved for the case where it is literally true: products exist,
     * and not one of them resolves to a rate anybody could be paid from.
     */
    public function test_a_company_with_no_usable_rate_for_any_product_reads_as_missing(): void
    {
        [$company, $product] = $this->companyWithProduct();
        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk();

        $response->assertJsonPath('state', 'missing');
        $response->assertJsonPath('blocking_step', 3);
        $response->assertJsonPath('products_total', 1);
        $response->assertJsonPath('products_covered', 0);
        $this->assertSame('products_without_rate', $response->json('issues.0.code'));
        $this->assertSame(1, $response->json('issues.0.count'));
        // The label is what the banner renders verbatim — Thai, and it names
        // the number rather than saying "incomplete".
        $this->assertStringContainsString('1 จาก 1', $response->json('issues.0.label'));
    }

    /**
     * AMBER, the partial-coverage half. Two products, one rate scoped to one
     * of them: the company IS paying somebody, so painting this the same
     * colour as "nobody is paid" is how a red banner becomes wallpaper.
     */
    public function test_partial_product_coverage_reads_as_incomplete(): void
    {
        [$company, $covered] = $this->companyWithProduct();
        Product::factory()->for($company)->create(['category_id' => null, 'brand_id' => null]);
        $this->companyDefaultOverrideRule($company);

        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'product_id' => $covered->id,
            'product_category_id' => null,
            'cert_tier_id' => null,
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
        ]);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk();

        $response->assertJsonPath('state', 'incomplete');
        $response->assertJsonPath('blocking_step', 3);
        $response->assertJsonPath('products_total', 2);
        $response->assertJsonPath('products_covered', 1);
    }

    /**
     * AMBER, the leader half — and the reason `blocking_step` is not simply
     * derived from `state`. Every product is covered, every agent gets paid,
     * and the team leader above them is silently skipped because no
     * commission_override_rules row resolves. Step 4, not step 3.
     */
    public function test_a_unilevel_company_with_no_leader_rate_reads_as_incomplete_at_step_four(): void
    {
        [$company] = $this->companyWithProduct();
        $this->companyDefaultRule($company);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk();

        $response->assertJsonPath('state', 'incomplete');
        $response->assertJsonPath('blocking_step', 4);
        $response->assertJsonPath('products_covered', 1);
        $this->assertSame('leader_rate_missing', $response->json('issues.0.code'));
    }

    /** GREEN — and the banner's contract is that green renders NOTHING at all. */
    public function test_a_fully_configured_company_reads_as_ready_with_no_issues(): void
    {
        [$company] = $this->companyWithProduct();
        $this->companyDefaultRule($company);
        $this->companyDefaultOverrideRule($company);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk();

        $response->assertJsonPath('state', 'ready');
        $response->assertJsonPath('blocking_step', null);
        $response->assertJsonPath('issues', []);
        $response->assertJsonPath('products_total', 1);
        $response->assertJsonPath('products_covered', 1);
    }

    // -----------------------------------------------------------------
    // 1b. PV (2026-09-12)
    // -----------------------------------------------------------------

    /**
     * THE GAP PV CREATES, AND THE ONLY THING THAT MAKES IT SURVIVABLE.
     *
     * CommissionBasisResolver falls back to the sale price when a PV company
     * sells a product nobody has given a PV to — deliberately, because the
     * alternative (a 0-satang row, or a refusal to pay) is worse. But that
     * fallback is SILENT at the point it happens: the rate resolves, the
     * gates pass, the ledger row is written, and the number is wrong in a
     * table BR-4 forbids anyone from correcting.
     *
     * Every other signal on this screen would say 'ready'. This is the one
     * that does not, and it is the entire reason the fallback is allowed to
     * be quiet.
     */
    public function test_a_pv_company_with_a_product_that_has_no_pv_reads_as_incomplete(): void
    {
        [$company, $product] = $this->companyWithProduct();
        $company->update(['commission_basis' => CommissionBasis::PointValue]);
        $product->update(['pv_satang' => null]);

        $this->companyDefaultRule($company);
        $this->companyDefaultOverrideRule($company);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk();

        $response->assertJsonPath('state', 'incomplete');
        // Step 2, where the basis and the PV figures are both answered —
        // not step 1, which would send an admin to re-pick a company they
        // have already picked.
        $response->assertJsonPath('blocking_step', 2);
        $this->assertSame('point_value_missing', $response->json('issues.0.code'));
    }

    public function test_a_pv_company_whose_products_all_have_a_pv_reads_as_ready(): void
    {
        // The control. Without it the test above would also pass against a
        // Service that called every PV company incomplete forever — which is
        // the failure mode that gets a banner ignored on principle.
        [$company, $product] = $this->companyWithProduct();
        $company->update(['commission_basis' => CommissionBasis::PointValue]);
        $product->update(['pv_satang' => 100000]);

        $this->companyDefaultRule($company);
        $this->companyDefaultOverrideRule($company);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('state', 'ready')
            ->assertJsonPath('issues', []);
    }

    public function test_a_price_basis_company_is_never_asked_about_pv(): void
    {
        /*
         * A company evaluating the switch may well fill PV in on some
         * products and not others before deciding. None of that is a gap
         * until the basis actually changes, and warning about it would be
         * the banner crying wolf about a setting nobody has turned on.
         */
        [$company, $product] = $this->companyWithProduct();
        $product->update(['pv_satang' => null]);

        $this->companyDefaultRule($company);
        $this->companyDefaultOverrideRule($company);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('state', 'ready')
            ->assertJsonPath('issues', []);
    }

    // -----------------------------------------------------------------
    // 2. The case this endpoint exists for
    // -----------------------------------------------------------------

    /**
     * THE WHOLE REASON THIS IS NOT A REUSE OF /config-health-report.
     *
     * That report answers with `commission_rules_count`, and this company's
     * count is 1. A naive `count() > 0` therefore reports it configured. The
     * rate expired a month ago, CommissionService::resolveCommissionRule()
     * will not find it, and every deal closed today pays nobody — which is
     * the exact state the owner asked to be warned about, arrived at by the
     * exact route a count cannot see.
     */
    public function test_an_expired_rate_does_not_count_as_coverage(): void
    {
        [$company] = $this->companyWithProduct();
        $this->companyDefaultRule($company, [
            'effective_from' => now()->subYears(2)->toDateString(),
            'effective_to' => now()->subMonth()->toDateString(),
        ]);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        // The count a coarser report would read is non-zero...
        $this->assertSame(1, CommissionRule::withoutGlobalScopes()->where('company_id', $company->id)->count());

        $response = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk();

        // ...and the answer is still "nobody gets paid".
        $response->assertJsonPath('state', 'missing');
        $response->assertJsonPath('products_covered', 0);
        $this->assertContains('rules_expired', array_column($response->json('issues'), 'code'));
    }

    /**
     * The same trap from the other side: a rate that starts next month is
     * just as useless today, and just as invisible to a count.
     */
    public function test_a_not_yet_effective_rate_does_not_count_as_coverage(): void
    {
        [$company] = $this->companyWithProduct();
        $this->companyDefaultRule($company, [
            'effective_from' => now()->addMonth()->toDateString(),
            'effective_to' => null,
        ]);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $response = $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk();

        $response->assertJsonPath('state', 'missing');
        $response->assertJsonPath('products_covered', 0);
        $this->assertContains('rules_not_yet_effective', array_column($response->json('issues'), 'code'));
    }

    /**
     * THE INVARIANT THAT KEEPS THE BANNER HONEST.
     *
     * CommissionReadinessService hand-scopes its own copy of the product ->
     * category -> company-wide resolution, because CommissionRule's
     * TenantScope cannot answer "is COMPANY X ready" for a Super Admin whose
     * ambient scope filters nothing. A hand-written copy of a money rule is a
     * copy that can drift, and if it drifts this banner lies about money in
     * one of two directions: it tells a company it is ready while
     * CommissionService finds no rule, or it nags about a gap that does not
     * exist until somebody learns to ignore it.
     *
     * So the two are asserted to agree, product by product, across every
     * scope the resolution order has: a product-scoped rate, a category one,
     * and the company-wide fallback. If this test goes red after a change to
     * resolveCommissionRule(), the fix is in CommissionReadinessService — not
     * here.
     */
    public function test_it_resolves_every_product_exactly_as_the_commission_service_does(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $productScoped = Product::factory()->for($company)->create();
        $categoryScoped = Product::factory()->for($company)->create();
        $fallback = Product::factory()->for($company)->create(['category_id' => null, 'brand_id' => null]);
        $uncovered = Product::factory()->for($company)->create(['category_id' => null, 'brand_id' => null]);

        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'product_id' => $productScoped->id,
            'product_category_id' => null,
            'cert_tier_id' => null,
            'effective_from' => now()->subYear()->toDateString(),
        ]);
        CommissionRule::factory()->create([
            'company_id' => $company->id,
            'product_id' => null,
            'product_category_id' => $categoryScoped->category_id,
            'cert_tier_id' => null,
            'effective_from' => now()->subYear()->toDateString(),
        ]);
        // An EXPIRED company-wide row alongside a live one — the tiebreak and
        // the date filter both have to agree, not just the scope order.
        $this->companyDefaultRule($company, ['effective_to' => now()->subDay()->toDateString(), 'effective_from' => now()->subYears(2)->toDateString()]);
        $liveDefault = $this->companyDefaultRule($company);

        $service = app(CommissionReadinessService::class);
        $commissions = app(CommissionService::class);

        $resolve = (new \ReflectionClass($service))->getMethod('resolveRuleForCompany');
        $resolve->setAccessible(true);

        $this->actingAs($superAdmin);

        foreach ([$productScoped, $categoryScoped, $fallback, $uncovered] as $product) {
            $this->assertSame(
                $commissions->resolveCommissionRule($product)?->id,
                $resolve->invoke($service, $product, (int) $company->id)?->id,
                "readiness and CommissionService disagree about product #{$product->id} — the banner would lie about money",
            );
        }

        // Control: the live company-wide row really is the one both sides
        // land on for the fallback product, so the assertion above is not
        // two nulls agreeing.
        $this->assertSame($liveDefault->id, $commissions->resolveCommissionRule($fallback)?->id);
    }

    // -----------------------------------------------------------------
    // 3. Scoping and authorization (BR-6)
    // -----------------------------------------------------------------

    /**
     * BR-6. A Company Admin is hard-scoped to their own company and a
     * client-supplied company_id is never trusted for that role — the same
     * shape ConfigHealthReportService::buildReport() uses.
     *
     * The concrete risk here is not a data leak so much as a LIE: a Company
     * Admin who could aim this at another company would be shown a verdict
     * about somebody else's money on every page of their own console.
     */
    public function test_a_company_admin_sees_only_their_own_company_even_when_asking_for_another(): void
    {
        [$own] = $this->companyWithProduct();
        $this->companyDefaultRule($own);
        $this->companyDefaultOverrideRule($own);

        [$other] = $this->companyWithProduct();

        $admin = User::factory()->companyAdmin()->create(['company_id' => $own->id]);

        // Their own company is fully configured...
        $this->actingAs($admin)->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('state', 'ready');

        // ...and naming the broken one does not change the answer.
        $this->actingAs($admin)->getJson(self::ENDPOINT."?company_id={$other->id}")
            ->assertOk()
            ->assertJsonPath('state', 'ready')
            ->assertJsonPath('products_total', 1);
    }

    /** A Super Admin may narrow, and gets that company's real answer. */
    public function test_a_super_admin_may_narrow_to_one_company(): void
    {
        [$broken] = $this->companyWithProduct();
        [$healthy] = $this->companyWithProduct();
        $this->companyDefaultRule($healthy);
        $this->companyDefaultOverrideRule($healthy);

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->getJson(self::ENDPOINT."?company_id={$broken->id}")
            ->assertOk()
            ->assertJsonPath('state', 'missing');

        $this->actingAs($superAdmin)->getJson(self::ENDPOINT."?company_id={$healthy->id}")
            ->assertOk()
            ->assertJsonPath('state', 'ready');
    }

    /**
     * A Super Admin on "ทุกบริษัท" gets the worst state found and step 1.
     *
     * There is no honest single verdict across tenants — "which products are
     * uncovered" has a different answer per company — so the endpoint reports
     * that something is wrong and points at the only action that can start
     * fixing it: pick a company.
     */
    public function test_a_super_admin_across_all_companies_is_pointed_at_step_one(): void
    {
        $this->companyWithProduct();
        [$healthy] = $this->companyWithProduct();
        $this->companyDefaultRule($healthy);
        $this->companyDefaultOverrideRule($healthy);

        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->getJson(self::ENDPOINT)->assertOk();

        $response->assertJsonPath('state', 'missing');
        $response->assertJsonPath('blocking_step', 1);
        $this->assertSame('companies_not_ready', $response->json('issues.0.code'));
        // Exactly one of the two is broken — a company with nothing to sell is
        // silent, so a stray factory company cannot inflate this number.
        $this->assertSame(1, $response->json('issues.0.count'));
    }

    /**
     * AGENTS GET 403, and this is a product decision rather than a data one.
     *
     * An agent cannot configure a commission rate — since 2026-09-11 neither
     * can a Company Admin — so the banner could only tell them that the
     * company is not going to pay them, on every page, with no way to act on
     * it. The owner's call: that is a morale problem, not a help. The agent
     * portal does not call this endpoint, and the endpoint refuses it anyway,
     * because "the frontend does not ask" is not a permission.
     */
    public function test_an_agent_is_refused(): void
    {
        [$company] = $this->companyWithProduct();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $this->actingAs($agent)->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // 4. can_fix — the server's answer, never re-derived on the client
    // -----------------------------------------------------------------

    /**
     * The 2026-09-11 decision made visible in one boolean.
     *
     * A Company Admin must be TOLD the state (they run the company, and an
     * agent will ask them first) but must never be handed a "go fix it"
     * button that walks into a 403 — commission config became Super-Admin-
     * only to write on the same day. The frontend is forbidden from deriving
     * this from a role string precisely so that the day the rule changes,
     * nothing has to be re-derived anywhere.
     */
    public function test_can_fix_follows_the_commission_rule_policy_and_not_the_role_string(): void
    {
        [$company] = $this->companyWithProduct();

        $companyAdmin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($companyAdmin)->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('can_fix', false);
        $this->actingAs($superAdmin)->getJson(self::ENDPOINT."?company_id={$company->id}")->assertOk()->assertJsonPath('can_fix', true);
    }

    /**
     * Only products this company can actually sell count toward the total.
     *
     * An inactive product is not a deal anybody can close, so counting it as
     * uncovered would manufacture an amber banner nobody can clear — the
     * fastest way to teach an admin to ignore the thing.
     */
    public function test_an_inactive_product_is_not_counted(): void
    {
        [$company] = $this->companyWithProduct();
        Product::factory()->for($company)->create(['is_active' => false, 'category_id' => null, 'brand_id' => null]);
        $this->companyDefaultRule($company);
        $this->companyDefaultOverrideRule($company);

        $admin = User::factory()->companyAdmin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('products_total', 1)
            ->assertJsonPath('state', 'ready');
    }
}
