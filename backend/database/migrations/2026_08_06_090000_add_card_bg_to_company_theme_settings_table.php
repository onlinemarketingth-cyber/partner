<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-055 follow-up (ADR-018) — let a tenant tint the content-card /
// surface colour (every `bg-white/95` card in the Agent Portal). Nullable
// → falls back to the neutral white surface resolved client-side.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->string('card_bg_hex', 7)->nullable()->after('nav_text_hex');
        });
    }

    public function down(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->dropColumn('card_bg_hex');
        });
    }
};
