<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-055 follow-up (ADR-018) — dedicated colour for the Agent Portal
// bottom-nav "active tab" icon/label. Previously this always followed the
// generated brand-600 ramp (primary_hex); this lets a company override just
// the bottom-nav active colour without changing the primary brand colour
// used elsewhere (buttons, links, etc). null = keep following brand-600.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->string('nav_active_hex')->nullable()->after('nav_text_hex');
        });
    }

    public function down(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->dropColumn('nav_active_hex');
        });
    }
};
