<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-055 follow-up (ADR-018) — per-company card border + shadow style.
//  card_border_hex: null = default slate border; 'none' = borderless; '#hex' = coloured.
//  card_shadow: null = default; one of none|sm|md|lg|xl.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->string('card_border_hex')->nullable()->after('card_text_hex');
            $table->string('card_shadow')->nullable()->after('card_border_hex');
        });
    }

    public function down(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->dropColumn(['card_border_hex', 'card_shadow']);
        });
    }
};
