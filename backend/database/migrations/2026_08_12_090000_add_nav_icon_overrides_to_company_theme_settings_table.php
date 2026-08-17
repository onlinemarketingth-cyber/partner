<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-057 — admin-configurable bottom-nav icons (BR-7). Mirrors
// label_overrides exactly (same JSON key=>value shape, same keys —
// nav_home/nav_clients/nav_academy/nav_commission/nav_profile — just a
// different value type: an Icon.vue icon name instead of display text).
// Kept as its own column rather than folded into label_overrides so the
// two concerns (text vs icon) stay independently nullable/clearable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->json('nav_icon_overrides')->nullable()->after('label_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->dropColumn('nav_icon_overrides');
        });
    }
};
