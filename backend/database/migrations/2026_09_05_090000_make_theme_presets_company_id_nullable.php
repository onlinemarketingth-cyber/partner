<?php

use App\Models\ThemePreset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-217 (human request, 2026-08-20: "แก้การบันทึกสีให้ใช้ได้กับทุกบริษัท")
 * — `theme_presets.company_id` becomes NULLABLE, and NULL now MEANS
 * something: "ชุดกลาง", a palette that belongs to the platform rather than
 * to one tenant, visible and applicable to every company.
 *
 * WHY A NULL RATHER THAN AN `is_shared` FLAG
 * ------------------------------------------
 * A boolean beside a still-NOT-NULL company_id would leave every shared
 * row owning a company it does not belong to, and every query would have
 * to remember to ignore that column — the exact shape that produces "why
 * is company A's name on the palette everyone uses". NULL says the row
 * has no owner, which is the truth, and it makes the wrong query fail
 * loudly (a plain `where company_id = X` simply stops returning it)
 * rather than quietly return a tenant-labelled global.
 *
 * BR-6 IS NOT WEAKENED. A preset holds ONLY the colour surface
 * (ThemePresetService::COLOR_FIELDS — hex values, gradient configs and a
 * shadow keyword) and never a name, logo, client, price or anything else
 * derived from a tenant. Sharing hex codes across companies is not
 * cross-tenant data exposure; it is the platform shipping a palette, which
 * is exactly what the five designed palettes already do — they are simply
 * copied N times today. Tenant-owned presets (company_id NOT NULL) keep
 * every existing guarantee unchanged: SharedOrTenantScope still filters
 * them by company, and ThemePresetPolicy still refuses company B's row to
 * company A.
 *
 * NOT DONE HERE, deliberately: the five `is_system` designed palettes stay
 * cloned per company. Collapsing them into five global rows would be a
 * data migration across every tenant to fix a duplication nobody has
 * complained about, on the same day this column changes shape. It can be
 * done later against a schema that already supports it; doing both at once
 * would make a bad afternoon indivisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Native ->change() (Laravel 12, no doctrine/dbal). The foreign key
        // and its cascadeOnDelete are untouched: a nullable FK is still a
        // FK, and a shared row simply has nothing to cascade FROM.
        Schema::table('theme_presets', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
        });
    }

    /**
     * IRREVERSIBLE ONCE USED, on purpose — the same stance as TASK-214's
     * migration. Re-imposing NOT NULL would require inventing an owner for
     * every shared preset, and there is no correct answer to "which single
     * company owns the palette all of them were using". So: refuse while
     * any shared row exists, and say what to do about it.
     */
    public function down(): void
    {
        $shared = ThemePreset::withoutGlobalScopes()->whereNull('company_id')->count();

        if ($shared > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$shared} shared theme preset(s) have company_id = NULL. "
                .'Delete them (or assign each one an owning company) first — this migration '
                .'will not guess which company should inherit a platform-wide palette.'
            );
        }

        Schema::table('theme_presets', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable(false)->change();
        });
    }
};
