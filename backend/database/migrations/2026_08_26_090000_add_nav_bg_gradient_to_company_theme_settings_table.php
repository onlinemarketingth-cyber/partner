<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-161 §3.1 — nav bar may now be a two-stop gradient, not just a flat
// colour. `nav_bg_hex` is string(7) and cannot hold a gradient, so this
// MIRRORS the shape the app background already uses on this same table
// (`background_type` / `background_config`) rather than inventing a second
// convention: a nullable type discriminator plus a nullable json config.
//
// Both columns are nullable ON PURPOSE and there is deliberately NO data
// migration: `nav_bg_hex` stays the solid value, and a null/absent
// `nav_bg_type` behaves exactly as "solid" did before — so every existing
// row keeps rendering identically with no backfill step (TASK-161 §3.1
// acceptance criterion 1). BR-7: the actual colours/angle are admin config,
// never hardcoded.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            // 'solid' | 'gradient'; null == solid (see above).
            $table->string('nav_bg_type')->nullable()->after('nav_bg_hex');
            // { color1, color2, angle } for the gradient type.
            $table->json('nav_bg_config')->nullable()->after('nav_bg_type');
        });
    }

    public function down(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->dropColumn(['nav_bg_type', 'nav_bg_config']);
        });
    }
};
