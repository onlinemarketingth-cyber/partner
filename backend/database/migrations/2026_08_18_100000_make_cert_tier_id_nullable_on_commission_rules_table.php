<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-035 — human research + decision (2026-08-18): neither traditional
// insurance brokerage nor MLM/direct-selling comp plans tie commission
// RATE to a certification/training tier — cert tier is a binary access
// gate (BR-1: must pass Basic to sell at all), never a rate multiplier.
// "Higher commission for better results" belongs to the Stairstep/
// Breakaway plan type's existing agent_ranks mechanic (ADR-011 §3c,
// TASK-031), not to Unilevel. So Unilevel's commission_rules resolution
// (CommissionService::resolveCommissionRule()) drops cert_tier_id from
// its query entirely — this migration makes the column nullable so new
// Unilevel rules can be created without one. The column itself stays
// (existing rows keep their historical value; other plan-type machinery
// — commission_override_rules — separately keys manager overrides by
// the MANAGER's own cert tier and is untouched by this change).
//
// Same "no doctrine/dbal, raw SQL for MySQL / full shadow-table rebuild
// for SQLite" pattern as every other nullable-a-NOT-NULL-FK migration in
// this project — see 2026_07_23_100000_add_category_scoping_to_commission_rules_table
// for the identical precedent this migration mirrors column-for-column.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: true);

            return;
        }

        DB::statement('ALTER TABLE commission_rules MODIFY cert_tier_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: false);

            return;
        }

        DB::statement('ALTER TABLE commission_rules MODIFY cert_tier_id BIGINT UNSIGNED NOT NULL');
    }

    /**
     * Exact column-for-column mirror of commission_rules' schema AS OF
     * this point in the migration chain (create table + TASK-024's
     * renewal fields + TASK-028's category scoping), with only
     * cert_tier_id's nullability toggled.
     */
    private function rebuildForSqlite(bool $nullable): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('commission_rules_rebuild_tmp', function (Blueprint $table) use ($nullable) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cert_tier_id')->nullable($nullable)->constrained('cert_tiers')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->string('rate_type');
            $table->unsignedInteger('rate_value');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('renewal_rate_type')->nullable();
            $table->unsignedInteger('renewal_rate_value')->nullable();
            $table->boolean('renewal_recurs')->default(false);
            $table->timestamps();
        });

        $columns = 'id, company_id, cert_tier_id, product_id, product_category_id, rate_type, rate_value, effective_from, effective_to, renewal_rate_type, renewal_rate_value, renewal_recurs, created_at, updated_at';
        DB::statement("INSERT INTO commission_rules_rebuild_tmp ({$columns}) SELECT {$columns} FROM commission_rules");

        Schema::drop('commission_rules');
        Schema::rename('commission_rules_rebuild_tmp', 'commission_rules');

        Schema::table('commission_rules', function (Blueprint $table) {
            $table->index(['company_id', 'cert_tier_id', 'product_id'], 'commission_rules_lookup_idx');
            $table->index(['company_id', 'cert_tier_id', 'product_category_id'], 'commission_rules_category_lookup_idx');
        });

        Schema::enableForeignKeyConstraints();
    }
};
