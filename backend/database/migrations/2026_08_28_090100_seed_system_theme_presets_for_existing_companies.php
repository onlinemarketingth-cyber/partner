<?php

use App\Models\Company;
use App\Services\Theme\ThemePresetService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

// TASK-164 §3 — the one-time half of "every company gets the five designed
// palettes". CompanyService::create() covers every FUTURE company; without
// this, only future tenants would get them and the tenants actually using
// the system would keep a preset list with nothing designed in it, which is
// the gap this task exists to close.
//
// Same shape and the same deliberate deviation as the §5.1 backfill it sits
// beside (2026_08_27_090000): it calls an application Service rather than
// writing raw SQL, because the palettes live in config/theme_presets.php and
// re-reading that file into hand-written INSERTs here would fork the
// definition of "what a designed palette is" into two places.
//
// IDEMPOTENT ON `key`: provisionDesignedPalettes() skips a company that
// already has a row for a given key, and the unique index added by the
// migration before this one enforces that at the database level too. A
// company created through the Admin UI between deploying and running this
// is therefore left exactly as it is, not duplicated.
//
// Runs provisionSystemPresets(), not just the palettes, so a company that
// somehow has no `ค่าเริ่มต้น` row at all (created before TASK-161, and
// missed because its backfill has not been run) still ends up whole.
return new class extends Migration
{
    public function up(): void
    {
        $service = app(ThemePresetService::class);

        // Safe on a fresh install: migrate runs before db:seed, so there are
        // no companies yet and this loop simply does not execute.
        Company::query()->orderBy('id')->chunkById(100, function ($companies) use ($service) {
            foreach ($companies as $company) {
                $service->provisionSystemPresets($company);

                Log::info(sprintf(
                    'TASK-164 §3 backfill: company %d — system theme presets ensured (%d designed palettes + "%s").',
                    $company->id,
                    count($service->designedPalettes()),
                    ThemePresetService::DEFAULT_PRESET_NAME,
                ));
            }
        });
    }

    public function down(): void
    {
        // Deliberately a NO-OP, for the same reason as the §5.1 backfill's
        // down(): there is no way to tell a seeded palette this migration
        // created from the identical row CompanyService::create() would have
        // created for a company registered afterwards. Deleting by key would
        // strip working presets off tenants that never came from here.
        //
        // Rolling back the migration BEFORE this one drops `key`/`is_system`
        // anyway, which downgrades every seeded palette into an ordinary
        // (deletable) preset — a state an admin can clean up in the UI.
        // Silently destroying colour sets is the worse failure.
    }
};
