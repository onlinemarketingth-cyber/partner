<?php

use App\Models\Company;
use App\Services\Theme\ThemePresetService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

// TASK-161 §5.1 (human decision, 2026-08-11) — the one-time half of "every
// company gets a ค่าเริ่มต้น preset".
//
// CompanyService::create() covers every FUTURE company. Without this
// migration only future tenants would benefit and the tenants actually
// using the system today would keep the empty list this task exists to
// remove.
//
// IDEMPOTENT by design: ThemePresetService::provisionDefault() skips a
// company that already has a preset by that name, so re-running (or
// running it after CompanyService has already provisioned a company) can
// only ever leave exactly one. Nothing is updated, so an admin who has
// since renamed or re-saved that preset keeps their version.
//
// DELIBERATE DEVIATION from the raw-DB::table() migration convention
// (2026_08_22_090000's docblock): this one calls an application Service.
// The whole point of §5.1 is that the snapshot holds the RESOLVED theme —
// the stored row with ThemeService::defaults() filled in — and a preset of
// raw nulls is precisely the failure the decision exists to prevent.
// Re-deriving "resolved" in raw SQL here would fork the definition of it
// away from ThemeResource/ThemeService, which is a worse problem than the
// coupling: two answers to "what colour is this company" is how one of
// them ends up wrong. Accepted cost, stated plainly: re-running this on a
// very old database produces a preset shaped by TODAY's COLOR_FIELDS, not
// the list as it stood on migration day. Harmless, because the skip above
// means it only ever runs for a company that has no such preset at all.
return new class extends Migration
{
    public function up(): void
    {
        $service = app(ThemePresetService::class);

        // Safe on a fresh install: migrate runs before db:seed, so there
        // are no companies yet and this loop simply does not execute.
        //
        // chunkById keeps the whole tenant table off the heap; there is no
        // filter on a column being written, so a plain cursor is stable
        // here (unlike TASK-134a's backfill).
        Company::query()->orderBy('id')->chunkById(100, function ($companies) use ($service) {
            foreach ($companies as $company) {
                $preset = $service->provisionDefault($company);

                Log::info(sprintf(
                    'TASK-161 §5.1 backfill: company %d — default theme preset "%s" (#%d) %s.',
                    $company->id,
                    ThemePresetService::DEFAULT_PRESET_NAME,
                    $preset->id,
                    $preset->wasRecentlyCreated ? 'created' : 'already existed, left untouched',
                ));
            }
        });
    }

    public function down(): void
    {
        // Deliberately a NO-OP.
        //
        // There is no way to tell a preset this migration created from one
        // a Company Admin saved under the same name afterwards — no marker
        // column, and adding one to carry a rollback is not worth the
        // schema. Deleting by name would therefore be data loss dressed up
        // as a rollback (same judgement as TASK-134a's down(), which took
        // the opposite decision only because it could scope itself to two
        // known system ids).
        //
        // Rolling back leaves an extra preset behind. That is a row an
        // admin can delete from the UI in one click; the alternative is
        // silently destroying a colour set somebody saved.
    }
};
