<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-042 §3 — BR-4: "never conflate" a promotion bonus with ordinary
// product commission. earned_via = CommissionEarnedVia::PromotionBonus
// is the actual distinguishing marker (same role earned_via already
// plays for every other row shape); this column is the same
// nullable-FK-per-earned_via pattern already established by
// source_binary_cycle_id (added 2026_07_14_200000, null unless
// earned_via = binary_match) and override_source_agent_id (null unless
// earned_via = override) — null on every row except a future
// earned_via = promotion_bonus one, giving direct traceability back to
// the campaign without a join through agent_promotion_credits.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->foreignId('source_agent_promotion_id')->nullable()->after('source_binary_cycle_id')
                ->constrained('agent_promotions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_agent_promotion_id');
        });
    }
};
