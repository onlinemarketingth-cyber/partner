<?php

namespace Tests\Feature\Console;

use App\Enums\PaymentStatus;
use App\Models\CommissionLedger;
use App\Models\CommissionWithdrawalRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * uat:reset must know about every table, and must be honest about production.
 *
 * ═══ WHAT WENT WRONG THE FIRST TIME ═══
 *
 * The command shipped on 2026-08-03 listing the tables to DELETE and saying
 * nothing about the rest. Six weeks later fifteen tables existed that it had
 * never heard of — five of them carrying money (the agent withdrawal request
 * and its items, and the three supplier settlement/payout tables).
 *
 * Running it in that state would have emptied `commission_ledger` and left
 * the payout queue full of requests pointing at rows that no longer existed:
 * a real agent's name, a real amount, a button that settles nothing. Worse
 * than not clearing at all, and nothing anywhere would have said so.
 *
 * ═══ WHAT THIS FILE IS FOR ═══
 *
 * The first test is the one that matters, and it is not really about today's
 * schema: it is the tripwire that makes the NEXT migration a decision. A
 * table in no list fails here, in CI, on the pull request that adds it —
 * rather than in six weeks, on production, at a cutover.
 */
class UatResetClassificationTest extends TestCase
{
    use RefreshDatabase;

    // ── The tripwire ────────────────────────────────────────────────────────

    public function test_every_table_in_the_schema_is_classified(): void
    {
        /*
         * If this fails, a migration added a table and nobody said what a
         * reset should do with it. The command prints the names; add each one
         * to KEEP (config, identity, catalogue, Academy content) or to WIPE /
         * ACADEMY_PROGRESS / CONTENT (data a test run produced).
         *
         * Do not "fix" this by making the command skip unknown tables. Skipping
         * is precisely what left five money tables behind.
         */
        $this->artisan('uat:reset', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_it_refuses_to_run_when_a_table_is_unclassified(): void
    {
        // The guard itself, proven rather than assumed — a tripwire nobody has
        // seen trip is a comment.
        Schema::create('something_nobody_classified', function ($table) {
            $table->id();
        });

        $this->artisan('uat:reset', ['--dry-run' => true])
            ->expectsOutputToContain('something_nobody_classified')
            ->assertExitCode(1);

        Schema::drop('something_nobody_classified');
    }

    // ── The five tables that were missing ───────────────────────────────────

    public function test_a_payout_request_never_outlives_the_ledger_it_draws_on(): void
    {
        /*
         * THE BUG THIS REWRITE EXISTS FOR, asserted as behaviour rather than
         * as a list entry: whatever order the tables are listed in, a payout
         * request must not survive a reset that removes the commission rows
         * behind it.
         */
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);

        $ledger = CommissionLedger::factory()->create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'amount_satang' => 150_000,
            'payment_status' => PaymentStatus::Pending,
        ]);

        $request = CommissionWithdrawalRequest::create([
            'company_id' => $company->id,
            'agent_id' => $agent->id,
            'amount_satang' => 150_000,
            'status' => 'approved',
        ]);
        DB::table('commission_withdrawal_items')->insert([
            'commission_withdrawal_request_id' => $request->id,
            'commission_ledger_id' => $ledger->id,
            'allocated_satang' => 150_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('uat:reset', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('commission_ledger')->count());
        $this->assertSame(0, DB::table('commission_withdrawal_requests')->count(), 'a request pointing at a deleted ledger row is the failure this command exists to prevent');
        $this->assertSame(0, DB::table('commission_withdrawal_items')->count());
    }

    public function test_the_supplier_payout_tables_are_cleared_too(): void
    {
        // Same argument, other counterparty. These three were missing for the
        // same reason: they were added after the command was written.
        foreach (['supplier_settlement_ledger', 'supplier_withdrawal_requests', 'supplier_withdrawal_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->artisan('uat:reset', ['--dry-run' => true])
            ->expectsOutputToContain('supplier_settlement_ledger')
            ->assertExitCode(0);
    }

    // ── What the owner asked to keep ────────────────────────────────────────

    public function test_products_academy_content_companies_and_users_survive(): void
    {
        /*
         * Owner, 2026-09-19: "ผมให้เก็บสินค้าไว้ กับ Academy Company ไว้".
         *
         * Asserted as the DEFAULT, with no flags, because that is how it will
         * actually be run at the cutover.
         */
        $company = Company::factory()->create();
        $user = User::factory()->agent()->create(['company_id' => $company->id]);
        $product = Product::factory()->for($company)->create();

        $this->artisan('uat:reset', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_company_wide_commission_rates_survive_but_the_ledger_does_not(): void
    {
        // The two halves of "keep the config, clear what it produced".
        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        $rulesBefore = DB::table('commission_rules')->count();

        $this->artisan('uat:reset', ['--force' => true])->assertExitCode(0);

        $this->assertSame($rulesBefore, DB::table('commission_rules')->count());
        $this->assertSame(0, DB::table('commission_ledger')->count());
    }

    // ── Links ───────────────────────────────────────────────────────────────

    public function test_a_companys_signup_and_login_codes_survive_while_share_codes_do_not(): void
    {
        /*
         * A cutover must not break the signup link of the tenant that is about
         * to go into service — those codes resolve to a company_invite_codes
         * row and to companies.slug, both of which this command keeps. Share,
         * team-invite and payment codes point at rows it deletes, so leaving
         * them would publish URLs that resolve to nothing.
         */
        $company = Company::factory()->create();

        $keep = ['company_id' => $company->id, 'code' => 'keepme', 'group' => 'company_signup', 'created_at' => now(), 'updated_at' => now()];
        $drop = ['company_id' => $company->id, 'code' => 'dropme', 'group' => 'product_share', 'created_at' => now(), 'updated_at' => now()];
        DB::table('tracked_links')->insert([$keep, $drop]);

        $this->artisan('uat:reset', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseHas('tracked_links', ['code' => 'keepme']);
        $this->assertDatabaseMissing('tracked_links', ['code' => 'dropme']);
    }

    public function test_the_surviving_codes_do_not_keep_click_counts_over_an_empty_visits_table(): void
    {
        // Otherwise the links dashboard reports "47 clicks" against a visits
        // table this command just emptied — a number nobody can reconcile and
        // nobody will think to doubt.
        $company = Company::factory()->create();
        DB::table('tracked_links')->insert([
            'company_id' => $company->id, 'code' => 'keepme', 'group' => 'company_login',
            'click_count' => 47, 'unique_click_count' => 31, 'conversion_count' => 4,
            'first_clicked_at' => now()->subDay(), 'last_clicked_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('uat:reset', ['--force' => true])->assertExitCode(0);

        $row = DB::table('tracked_links')->where('code', 'keepme')->first();
        $this->assertSame(0, (int) $row->click_count);
        $this->assertSame(0, (int) $row->unique_click_count);
        $this->assertNull($row->last_clicked_at);
    }

    // ── Academy progress ────────────────────────────────────────────────────

    public function test_lesson_progress_goes_with_the_completions_it_belongs_to(): void
    {
        /*
         * `module_lesson_progress` and `module_lesson_quiz_attempts` were not
         * in the old list, so a reset left lessons ticked inside a module that
         * reported 0% — the two halves of one fact disagreeing on screen.
         */
        $this->artisan('uat:reset', ['--dry-run' => true])
            ->expectsOutputToContain('module_lesson_progress')
            ->assertExitCode(0);
    }

    public function test_keep_certs_spares_academy_progress(): void
    {
        // BR-1: wiping certifications blocks an agent who has already passed
        // Basic from selling until they retake it. The flag exists so a
        // cutover does not have to.
        $this->artisan('uat:reset', ['--dry-run' => true, '--keep-certs' => true])
            ->expectsOutputToContain('agents can sell straight away')
            ->assertExitCode(0);
    }

    // ── Production ──────────────────────────────────────────────────────────

    public function test_production_refuses_without_the_cutover_flag(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('uat:reset', ['--force' => true])
            ->expectsOutputToContain('--production-cutover')
            ->assertExitCode(1);
    }

    public function test_production_refuses_while_the_site_is_still_serving(): void
    {
        /*
         * Maintenance mode is not ceremony. A referral advancing into Complete
         * Payment writes ledger rows while this command is deleting them, and
         * BR-4 means what lands half-written cannot be corrected.
         */
        $this->app['env'] = 'production';

        $this->artisan('uat:reset', ['--production-cutover' => true, '--force' => true])
            ->expectsOutputToContain('artisan down')
            ->assertExitCode(1);
    }

    public function test_nothing_is_deleted_when_the_database_name_is_typed_wrong(): void
    {
        // --force is deliberately NOT honoured in production: a flag that
        // skips the prompt is exactly what a deploy script inherits by
        // accident.
        $this->app['env'] = 'production';
        // The contract, not the string key: Application::isDownForMaintenance()
        // resolves MaintenanceMode::class and falls back to the file driver,
        // so binding anything else leaves the real gate in place and the test
        // would pass for the wrong reason.
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });

        $company = Company::factory()->create();
        $agent = User::factory()->agent()->create(['company_id' => $company->id]);
        CommissionLedger::factory()->create(['company_id' => $company->id, 'agent_id' => $agent->id]);

        $this->artisan('uat:reset', ['--production-cutover' => true, '--force' => true])
            ->expectsQuestion('Type the database name to confirm', 'not-the-database')
            ->expectsOutputToContain('That is not the database name')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('commission_ledger')->count(), 'a mistyped confirmation must delete nothing');
    }
}
