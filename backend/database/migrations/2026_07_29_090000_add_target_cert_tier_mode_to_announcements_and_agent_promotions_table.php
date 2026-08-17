<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-042 §4 (Cert-tier "and above" targeting) — BR-7 confirmed
// 2026-07-23: admin can choose, per announcement/promotion, between
// exact tier match (today's only behavior) and "this tier and above"
// (using the already-existing cert_tiers.sort_order — no new ordering
// column, per task instructions). Default 'exact' on both tables is a
// backward-compatibility requirement, not just a convenience default:
// every existing row must keep its identical current targeting
// behavior (AnnouncementController::index() / AgentPromotion::
// appliesToAgent() both branch on this column and fall through to the
// pre-existing exact-match query when it's 'exact').
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('target_cert_tier_mode')->default('exact')->after('target_cert_tier_id'); // App\Enums\CertTierTargetMode
        });

        Schema::table('agent_promotions', function (Blueprint $table) {
            $table->string('target_cert_tier_mode')->default('exact')->after('target_cert_tier_id'); // App\Enums\CertTierTargetMode
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('target_cert_tier_mode');
        });

        Schema::table('agent_promotions', function (Blueprint $table) {
            $table->dropColumn('target_cert_tier_mode');
        });
    }
};
