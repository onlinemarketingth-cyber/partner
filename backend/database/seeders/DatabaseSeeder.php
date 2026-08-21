<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

// DEV-ONLY seed data. Passwords below are the Laravel default factory
// password ('password') — never use these accounts or this pattern
// outside local development. Tenant/identity data per TASK-001; Product
// Catalog data (with placeholder BR-7 values, clearly marked) is
// delegated to CatalogSeeder below.
//
// Idempotent by design (firstOrCreate, not factory()->create()) — this
// must be safe to run more than once against a database that already
// has data, since `php artisan db:seed` (no `:fresh`) is a normal thing
// to do after adding a new seeder (e.g. CatalogSeeder). Company::slug
// and User::email are both unique columns; factory()->create() ignored
// that and crashed with a UniqueConstraintViolationException on rerun.
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        /*
         * SECURITY AUDIT 2026-08-21 (V16) — THE WARNING AT THE TOP OF THIS
         * FILE IS NOW ENFORCED INSTEAD OF MERELY WRITTEN.
         *
         * It has always said "never use these accounts or this pattern
         * outside local development", and a comment has never stopped
         * anybody. What this seeder creates is a Super Admin whose password
         * is the literal string "password", and it is deliberately
         * idempotent (firstOrCreate) — which is exactly what makes it
         * dangerous: pointed at production it does not crash on the
         * existing data, it quietly succeeds and leaves a platform-wide
         * account with a known password behind, indistinguishable from a
         * legitimate one.
         *
         * `php artisan db:seed` against the wrong DB_DATABASE is one
         * mistyped command. And this codebase already documents that
         * tinker does not work on the production host, which makes
         * reaching for other artisan commands there MORE likely, not less.
         * CreateSuperAdminCommand is the safe production path; this guard
         * is what makes people take it.
         */
        abort_unless(
            app()->environment(['local', 'testing']),
            500,
            'DatabaseSeeder is dev-only seed data and refuses to run in "'.app()->environment().'". '
                .'To create the first Super Admin on a real environment, use: php artisan admin:create-super',
        );

        // Thai Life is explicitly named in CLAUDE.md Section 1 as the
        // first tenant — not an invented value.
        $thaiLife = Company::firstOrCreate(
            ['slug' => 'thai-life'],
            ['name' => 'Thai Life', 'is_active' => true],
        );

        // first_name/last_name/name all set explicitly below — this
        // seeder uses WithoutModelEvents, so User::booted()'s saving()
        // hook (which normally derives `name` from first_name+last_name)
        // never fires here (see migration 2026_07_12_090000 docblock).
        User::firstOrCreate(
            ['email' => 'superadmin@example.test'],
            ['name' => 'Super Admin (dev)', 'first_name' => 'Super', 'last_name' => 'Admin (dev)', 'password' => 'password', 'role' => UserRole::SuperAdmin, 'company_id' => null],
        );

        User::firstOrCreate(
            ['email' => 'admin@thailife.test'],
            ['name' => 'Thai Life Admin (dev)', 'first_name' => 'Thai Life', 'last_name' => 'Admin (dev)', 'password' => 'password', 'role' => UserRole::CompanyAdmin, 'company_id' => $thaiLife->id],
        );

        User::firstOrCreate(
            ['email' => 'agent@thailife.test'],
            ['name' => 'Thai Life Agent (dev)', 'first_name' => 'Thai Life', 'last_name' => 'Agent (dev)', 'password' => 'password', 'role' => UserRole::Agent, 'company_id' => $thaiLife->id],
        );

        // Two more agents (dev-only), added so Leaderboard has actual
        // competition to show instead of a single-row ranking — see
        // DemoActivitySeeder, which gives each of the 3 agents a
        // different amount of activity/XP so the ranking is meaningful.
        User::firstOrCreate(
            ['email' => 'niran@thailife.test'],
            ['name' => 'Niran Boonmee', 'first_name' => 'Niran', 'last_name' => 'Boonmee', 'password' => 'password', 'role' => UserRole::Agent, 'company_id' => $thaiLife->id],
        );
        User::firstOrCreate(
            ['email' => 'pim@thailife.test'],
            ['name' => 'Pim Wongsawat', 'first_name' => 'Pim', 'last_name' => 'Wongsawat', 'password' => 'password', 'role' => UserRole::Agent, 'company_id' => $thaiLife->id],
        );

        // TASK-055 / ADR-018 — Thai Life's white-label theme (seed/demo
        // values). Only needs the company above to exist.
        $this->call(CompanyThemeSeeder::class);

        // ADR-026 §3.1 (TASK-132) — the two system pipeline templates,
        // per company. Runs AFTER CompanyThemeSeeder because that seeder
        // creates the second demo tenant (GENESENN), and every company
        // needs its own medical_package_default (the resolver's final
        // fail-safe, ADR-026 §3.3). Runs BEFORE CatalogSeeder so products
        // exist in a world where templates already do.
        $this->call(PipelineTemplateSeeder::class);

        $this->call(CatalogSeeder::class);
        $this->call(AcademySeeder::class);
        $this->call(CustomerSeeder::class);
        // ReferralSeeder::class intentionally no longer called —
        // DemoActivitySeeder below fully supersedes it (creates
        // referrals for every seeded client via the real
        // ReferralService, not just agent@thailife.test's, and also
        // advances them through the pipeline instead of leaving every
        // one at CompleteRegistered). ReferralSeeder.php itself is left
        // in the repo rather than deleted (same "safe to delete
        // yourself" note as SETUP.md Section 5 — my sandbox can create
        // files but not remove them).
        $this->call(GamificationSeeder::class);
        // Runs last — depends on every domain above (cert tiers, modules,
        // exams, products, commission rules, clients, gamification
        // rules/badges) already existing. Walks all 3 agents through
        // real Service calls (not raw model inserts) so every UI screen
        // has non-empty, business-rule-correct data to test against —
        // see that file's own docblock for the full data-population plan.
        $this->call(DemoActivitySeeder::class);
        // ADR-017 (TASK-054) — Order & Payment Collection demo data. Runs
        // AFTER DemoActivitySeeder: it needs agent@thailife.test to have
        // passed Basic cert (BR-1) and the Standard Package product to
        // exist, and it produces `paid` orders whose commission_ledger rows
        // fire through the same PipelineService path the demo above uses.
        $this->call(OrderSeeder::class);
    }
}
