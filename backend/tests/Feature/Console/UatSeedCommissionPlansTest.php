<?php

namespace Tests\Feature\Console;

use App\Console\Commands\UatSeedCommissionPlansCommand as Seed;
use App\Enums\CommissionEarnedVia;
use App\Enums\IdDocumentType;
use App\Models\AffiliateAttributionSetting;
use App\Models\AgentRankSetting;
use App\Models\Client;
use App\Models\CommissionLedger;
use App\Models\Company;
use App\Models\Order;
use App\Models\Referral;
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

    public function test_both_rank_plans_arrive_with_the_company_level_rank_form_filled_in(): void
    {
        /*
         * Owner, 2026-09-22: "ผมไปดูค่าที่ setup ค่าคอม ของแผน stairstep
         * ทำไมถึงไม่เหมือนกัน".
         *
         * The ladder was seeded; the ONE settings row above it was not, so
         * the three boxes at the top of ขั้นที่ 2 rendered empty and the UAT
         * sheet described a screen that did not exist. A missing row is
         * invisible on the payout assertions above — every UAT agent is
         * placed on their rank by hand, so nothing this command checks would
         * ever have noticed. Hence a test of its own.
         *
         * Generation is included because it is built on Stairstep's ranks and
         * renders the same form; seeding only Stairstep would half-fix it.
         */
        foreach (['stairstep_breakaway', 'generation'] as $plan) {
            $company = $this->seedPlan($plan);

            $settings = AgentRankSetting::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->get();

            // The table is unique on company_id; more than one row means the
            // upsert idiom regressed into an insert.
            $this->assertCount(1, $settings, "{$plan} should have exactly one rank settings row");

            $row = $settings->first();
            $this->assertSame(Seed::RANK_TRAILING_WINDOW_DAYS, $row->trailing_window_days);
            $this->assertSame(Seed::RANK_RECALCULATION_FREQUENCY, $row->recalculation_frequency);
            $this->assertSame(Seed::RANK_VOLUME_SCOPE, $row->volumeScope());
        }
    }

    public function test_the_affiliate_structure_form_arrives_filled_in_too(): void
    {
        // Found while fixing the rank form: Affiliate had the same hole —
        // the override rule was seeded, the settings row behind
        // "หน้าต่างนับเครดิต (วัน)" was not, so QA was told to check a field
        // that could only ever be blank.
        $company = $this->seedPlan('affiliate');

        $row = AffiliateAttributionSetting::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->sole();

        $this->assertSame(Seed::AFFILIATE_ATTRIBUTION_WINDOW_DAYS, $row->attribution_window_days);
        // Off on purpose: no differential calculation exists behind it yet.
        $this->assertFalse($row->new_vs_returning_rate_differential_enabled);
    }

    public function test_the_four_plans_that_do_not_use_ranks_get_no_rank_settings(): void
    {
        // The form only exists on the two rank screens. A row on a Binary or
        // Affiliate tenant would be configuration QA is asked to check on a
        // screen that never shows it.
        foreach (['unilevel', 'binary', 'matrix', 'affiliate'] as $plan) {
            $company = $this->seedPlan($plan);

            $this->assertSame(0, AgentRankSetting::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->count(), "{$plan} should carry no rank settings");
        }
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
         * ── THIS TEST EXISTED AND COULD NOT FAIL ──
         *
         * 2026-09-22. It compared `payouts()` before and after, and
         * `payouts()` is a map keyed by "name · earned_via" — so a second,
         * identical set of ledger rows OVERWROTE the first set's keys and the
         * map came out unchanged. The assertion held while production was
         * doubling: ฿1,900 became ฿3,800 on the owner's screen and this test
         * stayed green.
         *
         * A test whose subject is duplication must therefore count. Rows, the
         * sum, the referrals and the orders — because each of those is a
         * separate way for a re-run to leave a mark, and the sum alone would
         * miss a row that duplicated at zero.
         */
        $company = $this->seedPlan('unilevel');

        $rows = fn (): array => [
            'ledger' => CommissionLedger::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            'satang' => (int) CommissionLedger::withoutGlobalScopes()->where('company_id', $company->id)->sum('amount_satang'),
            'referrals' => Referral::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            'orders' => Order::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            'clients' => Client::withoutGlobalScopes()->where('company_id', $company->id)->count(),
            'agents' => User::withoutGlobalScopes()->where('company_id', $company->id)->count(),
        ];

        $before = $rows();
        $this->assertSame(['ledger' => 4, 'satang' => 190_000, 'referrals' => 1, 'orders' => 1, 'clients' => 1, 'agents' => 4], $before);

        $this->seedPlan('unilevel');
        $this->seedPlan('unilevel');

        $this->assertSame($before, $rows());
        $this->assertSame(1, Company::withoutGlobalScopes()
            ->where('slug', 'like', Seed::SLUG_PREFIX.'%')->count());
    }

    public function test_re_running_every_plan_leaves_the_ledger_exactly_as_it_was(): void
    {
        // The same property across all six, because each plan reaches `sell()`
        // by its own path and only Unilevel was ever checked.
        $this->artisan('uat:seed-commission-plans', ['--force' => true])->assertSuccessful();

        $before = CommissionLedger::withoutGlobalScopes()
            ->selectRaw('company_id, count(*) as rows_count, sum(amount_satang) as satang')
            ->groupBy('company_id')->orderBy('company_id')->get()->toArray();

        $this->artisan('uat:seed-commission-plans', ['--force' => true])->assertSuccessful();

        $after = CommissionLedger::withoutGlobalScopes()
            ->selectRaw('company_id, count(*) as rows_count, sum(amount_satang) as satang')
            ->groupBy('company_id')->orderBy('company_id')->get()->toArray();

        $this->assertSame($before, $after);
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

    // ── The payout queue can reach them ─────────────────────────────────────

    public function test_every_uat_agent_is_ready_to_be_paid_out(): void
    {
        /*
         * 2026-09-22 — the owner opened the payout screen for the Unilevel
         * tenant and found all four agents under "มีค่าแนะนำ แต่ตั้งจ่ายไม่ได้".
         * The screen was right: it refuses to queue money for somebody with no
         * account to send it to and no verified identity. The FIXTURE was
         * unfinished, and a UAT tenant that cannot reach the payout queue
         * leaves the second half of the money path untested.
         *
         * `hasCompletePayoutDetails()` is the one definition the whole payout
         * flow gates on, so this asserts through it rather than listing the
         * five columns again and drifting from it.
         */
        $company = $this->seedPlan('unilevel');

        $agents = User::withoutGlobalScopes()->where('company_id', $company->id)->get();

        $this->assertCount(4, $agents);
        foreach ($agents as $agent) {
            $this->assertTrue(
                $agent->hasCompletePayoutDetails(),
                "{$agent->name} cannot be paid out — the payout queue will hide them",
            );
        }
    }

    public function test_the_fabricated_identity_cannot_be_mistaken_for_a_real_one(): void
    {
        /*
         * national_id is encrypted and mirrored into national_id_hash, a
         * DETERMINISTIC blind index the user search matches on. A plausible
         * 13-digit Thai ID invented for a test account could hash to the same
         * value as a real person's — on a production database.
         *
         * So the document is a UAT-prefixed passport, and this test is what
         * stops somebody "tidying" it into a realistic Thai ID later.
         */
        $company = $this->seedPlan('unilevel');

        foreach (User::withoutGlobalScopes()->where('company_id', $company->id)->get() as $agent) {
            $this->assertSame(IdDocumentType::Passport, $agent->id_document_type);
            $this->assertStringStartsWith('UAT', (string) $agent->national_id);
            $this->assertStringStartsWith('UAT', (string) $agent->bank_account_number);
        }
    }

    public function test_exactly_one_tenant_withholds_tax_so_both_cases_are_visible(): void
    {
        /*
         * Withholding is orthogonal to the plan, so covering it means either
         * twelve tenants or one deliberate pairing. Five withhold nothing and
         * Affiliate withholds 3% — which is what lets QA compare gross against
         * net side by side instead of taking one screen's word for it.
         */
        $this->artisan('uat:seed-commission-plans', ['--force' => true])->assertSuccessful();

        $rates = Company::withoutGlobalScopes()
            ->where('slug', 'like', Seed::SLUG_PREFIX.'%')
            ->pluck('wht_rate', 'slug')
            ->all();

        $this->assertSame(Seed::WHT_RATE_BP, $rates[Seed::SLUG_PREFIX.'affiliate']);
        foreach (['unilevel', 'binary', 'matrix', 'stairstep-breakaway', 'generation'] as $plan) {
            $this->assertNull($rates[Seed::SLUG_PREFIX.$plan], "{$plan} should not withhold");
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
