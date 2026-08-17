<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-072 — human-confirmed via AskUserQuestion (2026-08-02): banners can
// be pinned to one of 3 fixed spots on the Agent Portal product page
// (see App\Enums\StorefrontBannerPlacement). Default 'top' preserves the
// exact current behavior for every banner created before this migration
// (there was only ever one spot until now).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_banners', function (Blueprint $table) {
            $table->string('placement')->default('top')->after('product_id');
        });

        // Index alongside the existing tenant/active/sort lookup — the
        // Agent Portal fetches all active banners once and groups by
        // placement client-side (small row counts), but company_id +
        // is_active + placement covers a future server-side filter too.
        Schema::table('storefront_banners', function (Blueprint $table) {
            $table->index(['company_id', 'is_active', 'placement', 'sort_order'], 'storefront_banners_placement_idx');
        });
    }

    public function down(): void
    {
        Schema::table('storefront_banners', function (Blueprint $table) {
            $table->dropIndex('storefront_banners_placement_idx');
            $table->dropColumn('placement');
        });
    }
};
