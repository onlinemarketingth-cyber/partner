<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-055 follow-up (ADR-018) — card/surface TEXT colour, so a tenant
// that tints cards dark can keep the text readable. Nullable → cards keep
// their default slate text hierarchy until this is set.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->string('card_text_hex', 7)->nullable()->after('card_bg_hex');
        });
    }

    public function down(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->dropColumn('card_text_hex');
        });
    }
};
