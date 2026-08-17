<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ag-lead finding (2026-07-14), surfaced while building the Binary
// commission schema, flagged per CLAUDE.md Guardrail 6 rather than
// fixed silently:
//
// 2026_07_09_200000_add_unique_referral_id_to_commission_ledger_table
// enforces "at most ONE commission_ledger row per referral" at the DB
// level. That migration's own comment already anticipated this exact
// conflict: "Flag to a human if a future business rule ever needs
// multiple commission events per referral (e.g. renewal commissions)
// — this constraint and the Service's current assumption would both
// need revisiting together." That future has arrived: TASK-024
// (renewal — a second ledger row on the same referral, later on the
// same referral again if renewal_recurs), TASK-025 (override — extra
// ledger rows for each manager up the chain, same referral_id),
// TASK-026 (split — two ledger rows, same referral_id) were all
// already specced assuming multiple rows per referral, and Binary's
// matched-volume payouts need the same relaxation. None of those can
// work under the current unique constraint.
//
// Fix: drop the blanket unique(referral_id) constraint.
// "At most one DIRECT-sale row per referral" is still the intended
// invariant (BR-4) — CommissionService::recordForReferral()'s
// existing check-then-create guard in application code remains the
// enforcement point for that narrower rule (same "app code is the
// real guard, DB constraint was only ever the backstop" philosophy
// the original migration itself documented). A composite DB-level
// backstop (e.g. unique on referral_id+earned_via) is intentionally
// NOT added here: renewal rows share earned_via='renewal' but recur
// annually on the same referral, so even that composite key isn't
// unique long-term. Revisit if ag-dev finds a cleaner DB-level
// invariant when actually implementing TASK-024/025/026.
//
// Also, per the human's decision to build Binary's schema now
// (ADR-006 Round 4): a binary_match ledger row is earned from a
// matched-volume CYCLE (commission_binary_settings/
// binary_matching_cycles), not from any single referral/product/cert
// tier — so referral_id, cert_tier_id_at_time, and product_id must
// all become nullable. They remain mandatory in practice for
// earned_via = direct/override/renewal (enforced in
// CommissionService, not the DB — same philosophy as above). Uses
// raw SQL (not Schema::table()->change()) because doctrine/dbal
// isn't installed in this project — same constraint/precedent as
// 2026_07_13_120000_make_preferred_time_nullable_on_referrals_table.
// Live-run fix (2026-07-14): the first attempt at this migration failed
// with "Cannot drop index 'commission_ledger_referral_unique': needed
// in a foreign key constraint" — MySQL requires referral_id to always
// have SOME index backing its FK to `referrals`, and the unique index
// was the only one. Fix: add a plain (non-unique) index first so the
// FK still has something to lean on, THEN drop the unique one.
//
// Second bug found + fixed 2026-07-14 (live `php artisan test` run by
// the human surfaced it, human chose "keep raw SQL, no new dependency"
// over adding doctrine/dbal): the raw MODIFY statements above are
// MySQL-only and break on SQLite, which is what phpunit.xml actually
// uses for the test DB — every RefreshDatabase test was failing at
// migration time. SQLite has no ALTER COLUMN at all, so for SQLite
// this migration instead does the standard table-rebuild (create a
// shadow table with the FINAL desired shape — plain index, no unique,
// 3 nullable columns — copy every row across via explicit column
// lists, drop the original, rename the shadow into place). This
// shadow schema deliberately does NOT include earned_via /
// override_source_agent_id / source_binary_cycle_id — those are
// plain ADD COLUMN operations added by the NEXT migration
// (2026_07_14_200000), which SQLite supports natively without a
// rebuild, so they aren't needed here. MySQL's path is completely
// untouched.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: true);

            return;
        }

        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->index('referral_id', 'commission_ledger_referral_idx');
        });

        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropUnique('commission_ledger_referral_unique');
        });

        DB::statement('ALTER TABLE commission_ledger MODIFY referral_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE commission_ledger MODIFY cert_tier_id_at_time BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE commission_ledger MODIFY product_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: false);

            return;
        }

        DB::statement('ALTER TABLE commission_ledger MODIFY product_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE commission_ledger MODIFY cert_tier_id_at_time BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE commission_ledger MODIFY referral_id BIGINT UNSIGNED NOT NULL');

        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->unique('referral_id', 'commission_ledger_referral_unique');
        });

        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropIndex('commission_ledger_referral_idx');
        });
    }

    /**
     * Exact column-for-column mirror of
     * 2026_07_09_100150_create_commission_ledger_table's schema AS OF
     * this point in the migration chain (earned_via and friends don't
     * exist yet — they're added by 2026_07_14_200000, which runs
     * AFTER this one), with referral_id/cert_tier_id_at_time/
     * product_id's nullability toggled and the unique(referral_id)
     * replaced by a plain index (or restored, for down()).
     *
     * Live-run fix (2026-07-14, second bug this same day, same root
     * cause as the sibling referrals rebuild in
     * 2026_07_13_120000_make_preferred_time_nullable_on_referrals_table):
     * SQLite index names are unique DATABASE-WIDE, not per-table, so
     * defining a same-named index/unique on the shadow table while the
     * original `commission_ledger` (and its identically-named
     * index/unique) still existed collided. Fix: the shadow table is
     * created WITHOUT any named index/unique, data is copied, the OLD
     * table is dropped, the shadow is renamed into place, and only
     * THEN is the index/unique (re)created on the now-real
     * `commission_ledger` table.
     */
    private function rebuildForSqlite(bool $nullable): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('commission_ledger_rebuild_tmp', function (Blueprint $table) use ($nullable) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();

            // These are brand-new columns on a brand-new shadow table, not
            // an alteration of existing ones — ->nullable($nullable) sets
            // the modifier directly at creation time. No ->change() call
            // here: that method only applies to Schema::table() (altering
            // an EXISTING column) and would re-trigger the very
            // doctrine/dbal requirement this whole rebuild exists to avoid.
            $table->foreignId('referral_id')->nullable($nullable)->constrained('referrals')->restrictOnDelete();
            $table->foreignId('cert_tier_id_at_time')->nullable($nullable)->constrained('cert_tiers')->restrictOnDelete();
            $table->foreignId('product_id')->nullable($nullable)->constrained('products')->restrictOnDelete();

            $table->string('rate_type_applied');
            $table->unsignedInteger('rate_applied');
            $table->unsignedBigInteger('amount_satang');
            $table->string('payment_status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        $columns = 'id, company_id, agent_id, referral_id, cert_tier_id_at_time, product_id, rate_type_applied, rate_applied, amount_satang, payment_status, paid_at, created_at, updated_at';
        DB::statement("INSERT INTO commission_ledger_rebuild_tmp ({$columns}) SELECT {$columns} FROM commission_ledger");

        Schema::drop('commission_ledger');
        Schema::rename('commission_ledger_rebuild_tmp', 'commission_ledger');

        Schema::table('commission_ledger', function (Blueprint $table) use ($nullable) {
            $table->index(['company_id', 'agent_id', 'payment_status'], 'commission_ledger_agent_status_idx');

            if ($nullable) {
                $table->index('referral_id', 'commission_ledger_referral_idx');
            } else {
                $table->unique('referral_id', 'commission_ledger_referral_unique');
            }
        });

        Schema::enableForeignKeyConstraints();
    }
};
