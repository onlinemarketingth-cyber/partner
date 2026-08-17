<?php

use App\Services\Theme\ThemePresetService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TASK-164 §1/§2 — system presets that cannot be deleted.
//
// `is_system` — a preset the PLATFORM provisioned. Read-only: no delete,
// no rename, no overwrite; apply is allowed (that is the whole point of
// shipping designed palettes). Enforced in ThemePresetPolicy AND re-checked
// in ThemePresetService — a Policy alone has been insufficient in this
// codebase before (ModuleOrderService, TASK-151).
//
// `key` — the IDEMPOTENCY HANDLE for seeding, deliberately NOT the name.
// Seeding keys on `key` so a preset stays uniquely identifiable no matter
// what its label says. It is nullable because a preset a Company Admin
// saved has no key and never gets one; only provisioned rows carry one.
//
// §2 — the existing "ค่าเริ่มต้น" snapshot is auto-provisioned per company
// and is the company's restore point, so it is a system preset too and is
// migrated in place here rather than being re-created (re-creating it would
// discard whatever colours that company's restore point actually holds).
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so up() is re-runnable and therefore TESTABLE: the data
        // half below is the interesting part (it decides which existing
        // rows become read-only), and a test that has to re-implement it
        // instead of calling it proves nothing.
        if (Schema::hasColumn('theme_presets', 'is_system')) {
            $this->flagExistingDefaultPresets();

            return;
        }

        Schema::table('theme_presets', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('name');
            $table->string('key')->nullable()->after('is_system');

            // One row per (company, key). This is what makes seeding
            // idempotent at the DATABASE level and not merely in the
            // Service: two concurrent provisioning runs (company creation
            // racing the backfill) cannot both insert `gold_classic`.
            // Multiple NULL keys per company are still fine — both MySQL
            // and SQLite treat NULLs as distinct in a unique index, which
            // is exactly the behaviour user-saved presets need.
            $table->unique(['company_id', 'key']);
        });

        $this->flagExistingDefaultPresets();
    }

    /**
     * §2 — upgrade the rows TASK-161 §5.1 already created. Matched on the
     * name it was provisioned under; a company whose admin has since
     * renamed it keeps a plain user preset, which is the honest outcome (we
     * cannot tell that row apart from one they saved).
     *
     * Raw DB::table() on purpose here (unlike the §5.1 backfill, which
     * needed a Service to resolve colours): this touches two flag columns
     * and no business value, so there is nothing to fork. Crucially it does
     * NOT re-snapshot the colours — the restore point keeps whatever it
     * holds; only its identity handle and read-only flag are set.
     */
    private function flagExistingDefaultPresets(): void
    {
        DB::table('theme_presets')
            ->whereNull('key')
            ->where('name', ThemePresetService::DEFAULT_PRESET_NAME)
            ->update([
                'key' => ThemePresetService::DEFAULT_PRESET_KEY,
                'is_system' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('theme_presets', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'key']);
            $table->dropColumn(['is_system', 'key']);
        });
    }
};
