<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-055 follow-up (ADR-018) — split the single font_family into a
// per-script pair so a tenant can pick a Thai font AND a separate
// Latin/English font (Thai glyphs render in the Thai face, Latin glyphs
// in the Latin face, via a per-glyph font-family fallback stack).
// The legacy font_family column is kept as a back-compat fallback.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->string('font_family_thai')->nullable()->after('font_family');
            $table->string('font_family_latin')->nullable()->after('font_family_thai');
        });
    }

    public function down(): void
    {
        Schema::table('company_theme_settings', function (Blueprint $table) {
            $table->dropColumn(['font_family_thai', 'font_family_latin']);
        });
    }
};
