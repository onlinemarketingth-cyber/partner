<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-024/025 (earned_via, override_source_agent_id) + ADR-006 Round 4
// Binary (source_binary_cycle_id) — shared columns across all 4
// commission-expansion tasks. All backfill/default to the "today"
// case so every existing row and every existing direct-sale flow is
// unaffected (BR-4 — existing immutable rows are never rewritten,
// this migration only adds nullable/defaulted columns).
//
// earned_via default 'direct': every row created before this
// migration WAS a direct sale (the only case that has ever existed),
// so the default backfills them correctly with no data migration
// needed.
// override_source_agent_id: nullable, who the override was earned
// FROM (TASK-025) — null for direct/renewal/binary_match rows.
// source_binary_cycle_id: nullable FK -> binary_matching_cycles
// (created in the previous migration) — null for every row except a
// future earned_via = binary_match row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->string('earned_via')->default('direct')->after('product_id'); // App\Enums\CommissionEarnedVia
            $table->foreignId('override_source_agent_id')->nullable()->after('earned_via')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('source_binary_cycle_id')->nullable()->after('override_source_agent_id')
                ->constrained('binary_matching_cycles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_binary_cycle_id');
            $table->dropConstrainedForeignId('override_source_agent_id');
            $table->dropColumn('earned_via');
        });
    }
};
