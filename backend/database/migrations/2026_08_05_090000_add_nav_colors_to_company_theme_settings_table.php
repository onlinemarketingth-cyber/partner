<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-055 follow-up (ADR-018) — let a tenant colour the app CHROME (the
// top bar + bottom-nav "menu"): its background and its text/icon colour,
// independent of the page primary/accent. Nullable → fall back to the
// neutral white-bar / slate-text default resolved client-side.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->string('nav_bg_hex', 7)->nullable()->after('accent_hex');
            $table->string('nav_text_hex', 7)->nullable()->after('nav_bg_hex');
        });
    }

    public function down(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->dropColumn(['nav_bg_hex', 'nav_text_hex']);
        });
    }
};
