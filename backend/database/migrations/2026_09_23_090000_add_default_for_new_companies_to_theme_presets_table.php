<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-08 (human: "พอมีบริษัทใหม่เราต้องมาตั้งค่าเอง หรือ Super Admin
 * เลือกได้ให้ใช้ได้ทุกบริษัท").
 *
 * TASK-217 made a palette VISIBLE to every company; the answer to the question
 * above was "visible yes, applied no" — a brand-new tenant still opened on the
 * platform's own colours until somebody went in and pressed "ใช้ชุดนี้". This
 * column is the missing half: which ชุดกลาง a company should be WEARING the
 * moment it is created.
 *
 * ── WHY A FLAG ON A PRESET AND NOT A SETTING SOMEWHERE ──
 *
 * The alternative was a platform-settings row holding a preset id. That is the
 * same fact stored twice — a preset that is deleted would leave the setting
 * pointing at nothing, and the screen where a Super Admin thinks about
 * palettes is the preset list, not a settings page they would have to
 * remember exists. Storing it ON the row means deleting the palette deletes
 * the choice, which is the honest outcome.
 *
 * ── WHY THERE IS NO UNIQUE INDEX ──
 *
 * "At most one row may be true" is not expressible as a unique index on MySQL
 * (every `false` row would collide), and a partial index is not portable to
 * the sqlite the test suite runs on. ThemePresetService::update() clears the
 * previous holder inside the same transaction that sets the new one, and
 * ThemePresetService::defaultForNewCompanies() reads with `first()` rather
 * than `sole()` — so even a row that somehow drifted in would pick a winner
 * instead of breaking company creation, which is the failure that would
 * matter.
 *
 * Existing companies are deliberately NOT touched: several have colours
 * somebody chose on purpose, and a migration that overwrote them would be
 * indistinguishable from a bug. The flag only ever affects companies created
 * after it is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theme_presets', function (Blueprint $table) {
            $table->boolean('is_default_for_new_companies')
                ->default(false)
                ->after('key');
        });
    }

    public function down(): void
    {
        Schema::table('theme_presets', function (Blueprint $table) {
            $table->dropColumn('is_default_for_new_companies');
        });
    }
};
