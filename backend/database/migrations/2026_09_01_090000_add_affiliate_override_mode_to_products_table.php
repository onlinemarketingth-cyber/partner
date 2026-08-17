<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-194 — per-product payout maths for the Affiliate plan's
// team-leader override (BR-2/BR-7): `App\Enums\AffiliateOverrideMode`
// values ('additive'|'deductive'). NULL = "additive" at calculation
// time (CommissionService), matching this table's existing
// commission_plan_type column pattern (2026_07_23_090000) — nullable
// and defaultless by design so every existing Affiliate-plan product
// keeps today's behavior (no manager payout at all, since this task's
// whole Affiliate branch is new) until a Company Admin explicitly picks
// a mode. This column has no effect on its own — it only changes
// anything once BOTH manager_id (users) AND a matching
// CommissionOverrideRule (TASK-025, reused unchanged) are also
// configured, same "nothing changes until explicitly wired" safety
// property TASK-025's own override already has.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('affiliate_override_mode')->nullable()->after('commission_plan_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('affiliate_override_mode');
        });
    }
};
