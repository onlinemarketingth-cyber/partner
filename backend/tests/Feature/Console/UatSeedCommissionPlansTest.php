<?php

namespace Tests\Feature\Console;

use App\Console\Commands\UatSeedCommissionPlansCommand as Seed;
use App\Enums\CommissionEarnedVia;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What each plan ACTUALLY pays, checked against arithmetic done by hand.
 *
 * ═══ WHY THE EXPECTED NUMBERS ARE WRITTEN OUT LONGHAND ═══
 *
 * The obvious way to test a seeder is to run it and assert that the ledger
 * matches what the engine produced — which asserts nothing at all, because
 * the engine produced it. Every figure below is instead derived from the
 * PLAN RULE and the fixture rates, in a comment, before the assertion. If
 * the engine disagrees with the comment, one of the two is wrong and that is
 * a finding worth having; a test that could not disagree would have none.
 *
 * The fixtures (Seed::PRICE_SATANG etc.) are deliberately round: ฿10,000 at
 * 10% is ฿1,000, and every number below can be checked without a calculator.
 *
 * ═══ AND WHY IT TESTS THE SEEDER AT ALL ═══
 *
 * The seeder is what QA will run against production. A bug in it does not
 * produce a wrong report — it produces a report of a system that was never
 * configured the way the sheet says, which is worse: QA would sign off on
 * numbers that came from a fixture mistake. So these tests are really about
 * the sheet being true.
 */
class UatSeedCommissionPlansTest extends TestCase
{
    use RefreshDatabase;

    private function seedPlan(string $plan): Company
    {
        $this->artisan('uat:seed-commission-plans', ['--force' => true, '--plan' => [$plan]])
            ->assertSuccessful();

        $company = Company::withoutGlobalScopes()
            ->firstWhere('slug', Seed::SLUG_PREFIX.str_replace('_', '-', $plan));

        $this->assertNotNull($company, "the seeder did not create a company for {$plan}");

        return $company;
    }

    /** @return array<string, int> name => amount_satang, so a failure names the person */
    private function payouts(Company $company): array
    {
        return CommissionLedger::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->with('agent')
            ->get()
            ->mapWithKeys(fn (CommissionLedger $row): array => [
                ($row->agent?->name ?? '—').' · '.$row->earned_via->value => $row->amount_satang,
            ])
            ->all();
    }

    // ── Unilevel ────────────────────────────────────────────────────────────

    public function test_unilevel_pays_the_seller_and_three_levels_of_leader(): void
    {
        /*
         * Base ฿10,000.00 = 1,000,000 satang, price basis.
         *   seller   10%  → 100,000
         *   level 1   5%  →  50,000
         *   level 2   3%  →  30,000
         *   level 3   1%  →  10,000
         * Additive, so the seller keeps the full 10% — the leaders are paid
         * by the company on top, not out of the seller's row.
         */
        $company = $this->seedPlan('unilevel');

        $this->assertEquals([
            'UAT ผู้ขาย · direct' => 100_000,
            'UAT หัวหน้าชั้น 1 · override' => 50_000,
            'UAT หัวหน้าชั้น 2 · override' => 30_000,
            'UAT หัวหน้าชั้น 3 · override' => 10_000,
        ], $this->payouts($company));
    }

    // ── Binary ──────────────────────────────────────────────────────────────

    public function test_binary_pays_the_two_sellers_now_and_the_sponsor_only_after_a_cycle(): void
    {
        /*
         * THE POINT OF SEEDING BINARY AT ALL. Two sales close, two sellers are
         * paid 10% each — and the sponsor, whose legs both just filled, is
         * paid nothing, because Binary settles on a CYCLE and not on a sale.
         *
         * An admin reading the settings screen has no way to learn this. A QA
         * tester who did not know it would file "Binary pays the upline
         * nothing" as a bug.
         */
        $company = $this->seedPlan('binary');

        $this->assertEquals([
            'UAT ขาซ้าย · direct' => 100_000,
            'UAT ขาขวา · direct' => 100_000,
        ], $this->payouts($company));

        // The volume IS there, waiting for the cycle: ฿10,000 on each leg.
        $this->assertDatabaseHas('binary_leg_volumes', [
            'company_id' => $company->id,
            'left_volume_satang' => Seed::PRICE_SATANG,
            'right_volume_satang' => Seed::PRICE_SATANG,
        ]);
    }

    public function test_the_binary_cycle_then_pays_the_sponsor_ten_percent_of_the_matched_leg(): void
    {
        /*
         * matched = min(left, right) = min(1,000,000, 1,000,000) = 1,000,000
         * 10% of that = 100,000 satang = ฿1,000.00, no cap configured.
         */
        $company = $this->seedPlan('binary');

        $this->artisan('commissions:run-binary-cycles')->assertSuccessful();

        $sponsor = User::withoutGlobalScopes()->firstWhere('name', 'UAT ผู้สนับสนุน');

        $this->assertSame(100_000, (int) CommissionLedger::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('agent_id', $sponsor->id)
            ->sum('amount_satang'));
    }

    // ── Matrix ──────────────────────────────────────────────────────────────

    public function test_matrix_pays_on_pv_not_on_price_and_walks_its_own_tree(): void
    {
        /*
         * THE BASIS TEST, carried by the one company that runs on PV.
         *
         * Base is the product's PV, ฿7,000.00 = 700,000 satang — NOT the
         * ฿10,000.00 price. Every figure is 70% of what the same rates would
         * pay on the price basis, and that is the whole difference a PV
         * company is buying:
         *   seller  10%  → 70,000
         *   level 1  5%  → 35,000
         *   level 2  3%  → 21,000
         */
        $company = $this->seedPlan('matrix');

        $this->assertEquals([
            'UAT ผู้ขาย · direct' => 70_000,
            'UAT ชั้นกลาง · matrix_override' => 35_000,
            'UAT ชั้นบนสุด · matrix_override' => 21_000,
        ], $this->payouts($company));
    }

    // ── Stairstep ───────────────────────────────────────────────────────────

    public function test_stairstep_pays_each_manager_only_the_difference_between_the_ranks(): void
    {
        /*
         * Ranks: starter 5%, leader 10%, manager 15% (breakaway).
         * Seller is starter, their manager is leader, theirs is manager.
         *
         *   seller, from commission_rules at the starter rate  5%  → 50,000
         *   leader  gets 10% − 5%  =  5%                           → 50,000
         *   manager gets 15% − 10% =  5%                           → 50,000
         *
         * All three equal by construction, so a transposed row would be
         * invisible — which is why the assertion is keyed by NAME.
         */
        $company = $this->seedPlan('stairstep_breakaway');

        $this->assertEquals([
            'UAT ผู้ขาย · direct' => 50_000,
            'UAT ผู้นำ · stairstep_override' => 50_000,
            'UAT ผู้จัดการ (ตัดสาย) · stairstep_override' => 50_000,
        ], $this->payouts($company));
    }

    // ── Generation ──────────────────────────────────────────────────────────

    public function test_generation_skips_the_uplines_who_have_not_broken_away(): void
    {
        /*
         * Chain above the seller: ก (starter), ข (breakaway), ค (starter),
         * ง (breakaway). A generation is counted only when a BREAKAWAY
         * ancestor is met, so:
         *
         *   seller  10%                → 100,000
         *   ข is generation 1,  5%     →  50,000
         *   ง is generation 2,  3%     →  30,000
         *   ก and ค are walked past and paid nothing at all.
         *
         * That ก earns zero while sitting directly above the sale is the
         * single most surprising thing this plan does, and the reason this
         * fixture alternates the ranks rather than making them all breakaway.
         */
        $company = $this->seedPlan('generation');

        $this->assertEquals([
            'UAT ผู้ขาย · direct' => 100_000,
            'UAT หัวหน้า ข (ตัดสาย) · generation_override' => 50_000,
            'UAT หัวหน้า ง (ตัดสาย) · generation_override' => 30_000,
        ], $this->payouts($company));
    }

    // ── Affiliate ───────────────────────────────────────────────────────────

    public function test_affiliate_pays_exactly_one_hop_up_and_does_not_reduce_the_seller(): void
    {
        /*
         *   seller     10% → 100,000
         *   introducer  3% →  30,000, additive: the company funds it, so the
         *                     seller's row is untouched. Under the deductive
         *                     mode the seller's row would be 70,000 instead,
         *                     which is the comparison the QA sheet asks for.
         */
        $company = $this->seedPlan('affiliate');

        $this->assertEquals([
            'UAT ผู้ขาย · direct' => 100_000,
            'UAT ผู้แนะนำ · override' => 30_000,
        ], $this->payouts($company));
    }

    // ── The properties every tenant must hold ───────────────────────────────

    public function test_every_plan_closes_a_real_paid_order_so_the_plan_lock_can_be_tested(): void
    {
        /*
         * Owner's ruling 2026-09-21: a paid order locks the plan, whether or
         * not commission followed. A UAT tenant with no order would exercise
         * half the lock and QA would sign off on the wrong half.
         */
        foreach (['unilevel', 'binary', 'matrix', 'stairstep_breakaway', 'generation', 'affiliate'] as $plan) {
            $company = $this->seedPlan($plan);

            $this->assertDatabaseHas('orders', [
                'company_id' => $company->id,
                'status' => 'paid',
            ]);
        }
    }

    public function test_re_running_the_seeder_does_not_duplicate_anything(): void
    {
        /*
         * QA will re-run this. If a second run doubled the ledger, every
         * expected figure on the sheet would be wrong from the second run
         * onward — and it would look like a commission bug, not a seeder one.
         */
        $company = $this->seedPlan('unilevel');
        $first = $this->payouts($company);

        $this->seedPlan('unilevel');

        $this->assertSame($first, $this->payouts($company));
        $this->assertSame(1, Company::withoutGlobalScopes()
            ->where('slug', 'like', Seed::SLUG_PREFIX.'%')->count());
    }

    public function test_it_creates_no_company_outside_the_uat_prefix(): void
    {
        // The safety property the purge command depends on.
        $before = Company::withoutGlobalScopes()->pluck('id')->all();

        $this->artisan('uat:seed-commission-plans', ['--force' => true])->assertSuccessful();

        $added = Company::withoutGlobalScopes()->whereNotIn('id', $before ?: [0])->get();

        $this->assertCount(6, $added);
        foreach ($added as $company) {
            $this->assertStringStartsWith(Seed::SLUG_PREFIX, $company->slug);
        }
    }

    public function test_the_uat_agents_cannot_be_logged_into(): void
    {
        /*
         * These tenants are created on a PRODUCTION deployment. Six accounts
         * with a known password would be six real ways in, minted to save a
         * step QA does not need — they verify through the admin console with
         * their own Super Admin account.
         */
        $company = $this->seedPlan('unilevel');

        $agents = User::withoutGlobalScopes()->where('company_id', $company->id)->get();

        $this->assertNotEmpty($agents);
        foreach ($agents as $agent) {
            $this->assertStringEndsWith('@uat.invalid', $agent->email);
            $this->assertFalse(
                password_verify('password', $agent->password),
                'a UAT agent accepts a guessable password',
            );
        }
    }

    // ── Purge ───────────────────────────────────────────────────────────────

    public function test_the_purge_removes_every_uat_tenant_and_leaves_the_rest_alone(): void
    {
        $real = Company::factory()->create(['slug' => 'a-real-company']);
        $this->artisan('uat:seed-commission-plans', ['--force' => true])->assertSuccessful();

        $this->artisan('uat:purge-commission-plans', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Company::withoutGlobalScopes()->withTrashed()
            ->where('slug', 'like', Seed::SLUG_PREFIX.'%')->count());
        $this->assertDatabaseHas('companies', ['id' => $real->id, 'slug' => 'a-real-company']);
    }

    public function test_the_purge_deletes_the_ledger_rows_that_br4_normally_protects(): void
    {
        /*
         * CommissionLedger::deleting always throws (BR-4). That rule is about
         * a real company's payout history; these rows belong to tenants that
         * never paid anybody, and without the query-builder delete the UAT
         * companies would be undeletable. Pinned because it is the one place
         * in the codebase allowed to do this.
         */
        $company = $this->seedPlan('unilevel');
        $this->assertGreaterThan(0, CommissionLedger::withoutGlobalScopes()->where('company_id', $company->id)->count());

        $this->artisan('uat:purge-commission-plans', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, CommissionLedger::withoutGlobalScopes()->where('company_id', $company->id)->count());
    }

    public function test_the_purge_refuses_when_there_is_nothing_of_its_own_to_delete(): void
    {
        // "Nothing to do" and "I deleted your UAT tenants" must not look the
        // same in a runbook.
        Company::factory()->create(['slug' => 'a-real-company']);

        $this->artisan('uat:purge-commission-plans', ['--force' => true])->assertFailed();

        $this->assertDatabaseHas('companies', ['slug' => 'a-real-company']);
    }

    public function test_an_unconfirmed_purge_deletes_nothing(): void
    {
        $this->seedPlan('unilevel');

        $this->artisan('uat:purge-commission-plans')
            ->expectsQuestion('Type PURGE UAT to confirm', 'yes')
            ->assertSuccessful();

        $this->assertSame(1, Company::withoutGlobalScopes()
            ->where('slug', 'like', Seed::SLUG_PREFIX.'%')->count());
    }

    public function test_the_ledger_rows_carry_the_earned_via_the_plan_implies(): void
    {
        /*
         * earned_via is what every payout report groups by. A Matrix override
         * filed as a plain `override` would land in the Unilevel bucket and
         * be invisible in the one place anybody would look for it.
         */
        $matrix = $this->seedPlan('matrix');

        $this->assertEqualsCanonicalizing(
            [CommissionEarnedVia::Direct->value, CommissionEarnedVia::MatrixOverride->value, CommissionEarnedVia::MatrixOverride->value],
            CommissionLedger::withoutGlobalScopes()->where('company_id', $matrix->id)
                ->pluck('earned_via')->map(fn ($v) => $v->value)->all(),
        );
    }
}
