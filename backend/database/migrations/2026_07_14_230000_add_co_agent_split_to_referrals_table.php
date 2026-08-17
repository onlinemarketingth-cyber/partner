<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-026 (ADR-006) — split commission between two co-selling agents.
// Both nullable, both-or-neither enforced in ReferralService (not the
// DB, same pattern as TASK-024's renewal_rate_type/value pair). Plain
// ADD COLUMN — no SQLite table-rebuild needed (unlike the earlier
// nullable-on-an-EXISTING-column migrations this session), since these
// are brand-new columns on both drivers.
//
// split_percentage is the share of the commission that goes to the
// CO-agent (co_agent_id); the referring agent (referrals.agent_id)
// gets the remainder, with any satang rounding remainder landing on
// the referring agent (see CommissionService::recordForReferral()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('co_agent_id')->nullable()->after('agent_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('split_percentage')->nullable()->after('co_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('co_agent_id');
            $table->dropColumn('split_percentage');
        });
    }
};
