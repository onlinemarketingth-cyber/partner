<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 3c (TASK-031) — recalculated periodically by
// RecalculateAgentRanks (see StairstepCommissionService::recalculateRanks())
// from trailing sales volume; never set directly by a user action, same
// "system-derived, not user-editable" precedent as users.current_rank_id
// being nullOnDelete (losing a rank definition should never break the
// user row, just leave them unranked until the next recalculation run).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_rank_id')->nullable()->after('binary_leg')
                ->constrained('agent_ranks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_rank_id');
        });
    }
};
