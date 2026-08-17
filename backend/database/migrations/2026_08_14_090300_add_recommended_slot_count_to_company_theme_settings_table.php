<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-068 / ADR-020 — BR-7 config field: how many product slots the
// Agent Portal's "recommended for you" row renders (pinned first, then
// ProductGradingService auto-fill). ag-dev's placement call (documented
// in the TASK-068 report): company_theme_settings is already the one-row-
// per-company "presentation config" singleton for this exact storefront
// screen (label_overrides, nav_icon_overrides, card colors, etc. all live
// there), so this joins that table rather than standing up a new
// single-column storefront_settings table for one value. default(8) is
// the sensible fallback ADR-020 calls for — never read as a hardcoded
// constant inside a Service; always this DB column (see
// ProductRecommendationService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->unsignedInteger('recommended_slot_count')->default(8)->after('nav_icon_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->dropColumn('recommended_slot_count');
        });
    }
};
