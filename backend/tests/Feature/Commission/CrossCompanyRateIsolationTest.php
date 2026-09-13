<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionEarnedVia;
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
use App\Services\Commission\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-12 — A REAL CROSS-COMPANY PAYOUT, FOUND FROM THE SCREEN.
 *
 * ── WHAT HAPPENED ──
 *
 * The owner set 5% on a product while working in Thai Life, switched the
 * company picker to AIA, and saw 5% again: "5% ทุกบริษัท คือที่ตั้งใจคือ thai
 * life อย่างเดียว". The screen's own copy of the bug was cosmetic. The one
 * underneath it was not.
 *
 * `CommissionService::resolveCommissionRule()` queried `CommissionRule::where(
 * ...)` and left the tenant filtering to TenantScope. That is correct in
 * exactly one situation — a request made by an authenticated Company Admin —
 * and a no-op in the two that decide real money:
 *
 *   · a GATEWAY-confirmed payment has no authenticated user at all;
 *   · a SUPER ADMIN is exempt from TenantScope by design (Section 5).
 *
 * In both, the lookup ran across EVERY company's rules and
 * `orderByDesc('effective_from')` picked whichever sorted first. On a
 * platform-owned product — one row every company sells (ADR-040) — that is
 * not a rare collision. It is the normal shape of the data: several companies
 * each holding their own rate for the same product_id.
 *
 * ExplainCommissionGapCommand has printed a warning about this exact condition
 * since 2026-09-11. The warning was right; nothing had acted on it.
 *
 * ── WHY THESE TESTS RUN WITH NO AUTHENTICATED USER ──
 *
 * Deliberately, and it is the whole point. `actingAs()` would install a user,
 * TenantScope would narrow the query, and every one of these would pass
 * against the broken code. The bug only exists where nobody is logged in —
 * which is where most payments are confirmed.
 */
class CrossCompanyRateIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gateway_confirmed_sale_pays_the_selling_companys_rate(): void
    {
        /*
         * THE OWNER'S CASE, as money rather than as pixels. Two companies sell
         * the same shared product. Mine pays 1%; the other pays 50%. A sale of
         * mine, confirmed by the payment gateway, must pay 1% — and paid 50%
         * before this fix, into a row BR-4 forbids anybody from correcting.
         */
        $world = $this->twoCompaniesSellingOneSharedProduct(myRate: 100, theirRate: 5000);

        $ledger = $this->sell($world['referral']);

        $this->assertSame(100, $ledger->rate_applied);
        $this->assertSame(1000, $ledger->amount_satang, '1% of 1,000.00 THB, not 50%');
        $this->assertSame($world['mine']->id, (int) $ledger->company_id);
    }

    public function test_the_other_companys_rate_is_not_borrowed_when_mine_is_missing_either(): void
    {
        /*
         * The harder half, and the one a naive fix misses. With NO rate of my
         * own, the honest answer is "this company has not configured one" —
         * CommissionService logs a warning, writes nothing, and the readiness
         * banner shouts. Silently borrowing the neighbour's rate would look
         * like success and pay a number nobody at this company ever approved.
         */
        $world = $this->twoCompaniesSellingOneSharedProduct(myRate: null, theirRate: 5000);

        $ledger = app(CommissionService::class)->recordForReferral($world['referral']->fresh(['agent', 'product', 'company']));

        $this->assertNull($ledger);
        $this->assertSame(0, CommissionLedger::withoutGlobalScopes()->count());
    }

    public function test_the_leader_override_is_scoped_the_same_way(): void
    {
        /*
         * Same defect one level up the chain: a team leader paid at another
         * company's override rate. resolveOverrideRule() had the identical
         * shape and needed the identical fix, so it needs its own test — a
         * fix applied to one of two twin queries is the one that rots.
         */
        $world = $this->twoCompaniesSellingOneSharedProduct(myRate: 100, theirRate: 100);

        $manager = User::factory()->agent()->create(['company_id' => $world['mine']->id]);
        UserCertification::create([
            'company_id' => $world['mine']->id,
            'user_id' => $manager->id,
            'cert_tier_id' => $world['tier']->id,
            'passed_at' => now(),
        ]);
        $world['agent']->update(['manager_id' => $manager->id]);

        $this->overrideRule($world['theirs']->id, $world['product']->id, 4000);
        $this->overrideRule($world['mine']->id, $world['product']->id, 200);

        $this->sell($world['referral']);

        $leaderRow = CommissionLedger::withoutGlobalScopes()
            ->where('agent_id', $manager->id)
            ->where('earned_via', CommissionEarnedVia::Override->value)
            ->sole();

        $this->assertSame(200, $leaderRow->rate_applied);
        $this->assertSame(2000, $leaderRow->amount_satang, '2% of 1,000.00 THB, not 40%');
    }

    public function test_a_company_wide_default_does_not_leak_across_tenants(): void
    {
        /*
         * The scope that leaks most easily, because it matches EVERY product:
         * a company-wide default carries neither product_id nor category_id,
         * so before the fix one company's fallback answered for every other
         * company's entire catalogue.
         */
        $world = $this->twoCompaniesSellingOneSharedProduct(myRate: null, theirRate: null);

        // Their company-wide default, and nothing at all for mine.
        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $world['theirs']->id,
            'product_id' => null,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => 9000,
            'effective_from' => now()->subDay(),
        ]);

        $this->assertNull(
            app(CommissionService::class)->recordForReferral($world['referral']->fresh(['agent', 'product', 'company'])),
            "my company has no rate; the neighbour's company-wide default is not mine to use",
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    /**
     * One PLATFORM-owned product (company_id null, ADR-040) that both
     * companies sell, priced at 1,000.00 THB so every percentage below is
     * readable at a glance.
     *
     * @return array{mine: Company, theirs: Company, product: Product, agent: User, referral: Referral, tier: CertTier}
     */
    private function twoCompaniesSellingOneSharedProduct(?int $myRate, ?int $theirRate): array
    {
        $tier = CertTier::firstOrCreate(['key' => 'basic'], ['name' => 'Basic', 'sort_order' => 1, 'is_mandatory' => true]);

        $product = Product::factory()->create([
            'company_id' => null,
            'price_satang' => 100000,
            'commission_plan_type' => CommissionPlanType::Unilevel,
        ]);

        $theirs = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel]);
        $mine = Company::factory()->create(['commission_plan_type' => CommissionPlanType::Unilevel]);

        /*
         * THEIRS IS CREATED FIRST, ON PURPOSE. The broken query ordered by
         * `effective_from` desc with no tie-break, so which row it returned
         * depended on insertion order — and a fixture that happened to create
         * mine first could pass against the bug by luck.
         */
        if ($theirRate !== null) {
            $this->agentRule($theirs->id, $product->id, $theirRate);
        }

        if ($myRate !== null) {
            $this->agentRule($mine->id, $product->id, $myRate);
        }

        $agent = User::factory()->agent()->create(['company_id' => $mine->id, 'manager_id' => null]);
        UserCertification::create([
            'company_id' => $mine->id,
            'user_id' => $agent->id,
            'cert_tier_id' => $tier->id,
            'passed_at' => now(),
        ]);

        $referral = Referral::create([
            'company_id' => $mine->id,
            'client_id' => Client::factory()->create([
                'company_id' => $mine->id,
                'referring_agent_id' => $agent->id,
            ])->id,
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'branch' => null,
            'preferred_time' => null,
            'current_stage' => PipelineStage::CompleteRegistered,
            'meeting_number' => null,
            'submitted_at' => now(),
        ]);

        return compact('mine', 'theirs', 'product', 'agent', 'referral', 'tier');
    }

    private function agentRule(int $companyId, int $productId, int $basisPoints): void
    {
        CommissionRule::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'product_category_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $basisPoints,
            'effective_from' => now()->subDay(),
        ]);
    }

    private function overrideRule(int $companyId, int $productId, int $basisPoints): void
    {
        CommissionOverrideRule::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'product_category_id' => null,
            'manager_cert_tier_id' => null,
            'rate_type' => CommissionRateType::Percentage,
            'rate_value' => $basisPoints,
            'effective_from' => now()->subDay(),
        ]);
    }

    private function sell(Referral $referral): CommissionLedger
    {
        // No actingAs() anywhere in this file — see the class docblock.
        $ledger = app(CommissionService::class)->recordForReferral($referral->fresh(['agent', 'product', 'company']));

        $this->assertNotNull($ledger, 'the fixture must produce a commission, or the assertions are vacuous');

        return $ledger->fresh();
    }
}
