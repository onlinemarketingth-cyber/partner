<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 2 (TASK-028) — closes the gap ADR-006 flagged and
// deferred ("Option B: nullable product_id as company-default row").
// commission_rules.product_id becomes nullable, and a new nullable
// product_category_id is added, so a rule can be scoped to an exact
// product (unchanged, existing behavior), a whole product category, or
// the whole company (both null) — resolved in that most-specific-wins
// order by CommissionService (see its own updated docblock). At most
// one of product_id/product_category_id may be set on a given row —
// enforced in StoreCommissionRuleRequest (Rule::prohibitedIf), not the
// DB, matching this project's established "app code is the real guard,
// DB constraint is only ever a backstop" philosophy (see
// 2026_07_14_130000_relax_referral_constraints_on_commission_ledger_table's
// own comment for precedent).
//
// Uses raw SQL for MySQL / a full shadow-table rebuild for SQLite — same
// established pattern as every other nullable-a-NOT-NULL-FK migration in
// this project (doctrine/dbal is not installed; see
// 2026_07_13_120000_make_preferred_time_nullable_on_referrals_table and
// 2026_07_14_130000_relax_referral_constraints_on_commission_ledger_table
// for the identical precedent this migration mirrors).
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: true);

            return;
        }

        // MySQL requires product_id to keep SOME index backing its FK
        // to `products` even while nullable — commission_rules_lookup_idx
        // (a composite starting with company_id, cert_tier_id) already
        // covers it, so no extra index-then-drop dance is needed here
        // (unlike the referral_id unique-constraint case this mirrors).
        DB::statement('ALTER TABLE commission_rules MODIFY product_id BIGINT UNSIGNED NULL');

        Schema::table('commission_rules', function (Blueprint $table) {
            $table->foreignId('product_category_id')->nullable()->after('product_id')
                ->constrained('product_categories')->restrictOnDelete();

            $table->index(['company_id', 'cert_tier_id', 'product_category_id'], 'commission_rules_category_lookup_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: false);

            return;
        }

        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropIndex('commission_rules_category_lookup_idx');
            $table->dropConstrainedForeignId('product_category_id');
        });

        DB::statement('ALTER TABLE commission_rules MODIFY product_id BIGINT UNSIGNED NOT NULL');
    }

    /**
     * Exact column-for-column mirror of commission_rules' schema AS OF
     * this point in the migration chain (create table + TASK-024's
     * renewal fields), with product_id's nullability toggled and
     * product_category_id added/removed.
     */
    private function rebuildForSqlite(bool $nullable): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('commission_rules_rebuild_tmp', function (Blueprint $table) use ($nullable) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cert_tier_id')->constrained('cert_tiers')->restrictOnDelete();
            $table->foreignId('product_id')->nullable($nullable)->constrained('products')->restrictOnDelete();

            if ($nullable) {
                $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            }

            $table->string('rate_type');
            $table->unsignedInteger('rate_value');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('renewal_rate_type')->nullable();
            $table->unsignedInteger('renewal_rate_value')->nullable();
            $table->boolean('renewal_recurs')->default(false);
            $table->timestamps();
        });

        $columns = 'id, company_id, cert_tier_id, product_id, rate_type, rate_value, effective_from, effective_to, renewal_rate_type, renewal_rate_value, renewal_recurs, created_at, updated_at';
        if ($nullable) {
            // product_category_id doesn't exist on the OLD table being
            // copied FROM yet (up()'s rebuild), so it's simply left NULL
            // on every copied row — correct, since every existing rule
            // is product-specific by definition before this migration.
            DB::statement("INSERT INTO commission_rules_rebuild_tmp ({$columns}) SELECT {$columns} FROM commission_rules");
        } else {
            // down()'s rebuild: the OLD table (about to be dropped) DOES
            // have product_category_id, but the shadow table being built
            // here doesn't (reverting to the pre-TASK-028 shape) — so it
            // is simply not selected, same "drop the column by omitting
            // it from the copy" approach used everywhere else in this
            // project's SQLite rebuilds.
            DB::statement("INSERT INTO commission_rules_rebuild_tmp ({$columns}) SELECT {$columns} FROM commission_rules");
        }

        Schema::drop('commission_rules');
        Schema::rename('commission_rules_rebuild_tmp', 'commission_rules');

        Schema::table('commission_rules', function (Blueprint $table) use ($nullable) {
            $table->index(['company_id', 'cert_tier_id', 'product_id'], 'commission_rules_lookup_idx');

            if ($nullable) {
                $table->index(['company_id', 'cert_tier_id', 'product_category_id'], 'commission_rules_category_lookup_idx');
            }
        });

        Schema::enableForeignKeyConstraints();
    }
};
