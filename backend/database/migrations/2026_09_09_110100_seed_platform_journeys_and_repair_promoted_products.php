<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-09 — the data half of "a journey travels with a platform product".
 *
 * Two jobs, in order:
 *
 *   1. CREATE THE TWO PLATFORM JOURNEYS. Every company already has its own
 *      copy of Direct Sale and Medical Package (PipelineTemplateProvisioner);
 *      these are the platform-owned twins a shared product can point at.
 *
 *   2. GIVE EVERY ALREADY-PROMOTED PRODUCT A JOURNEY AGAIN. Products
 *      promoted before this release had `pipeline_template_id` cleared, so
 *      they fell through to the Medical Package fail-safe and their share
 *      links lost the buy button. Human's ruling (2026-09-09): set them all
 *      to Direct Sale.
 *
 * ── WHY DIRECT SALE AND NOT "whatever it was before" ──
 *
 * The old value IS recoverable — `catalog:promote-products` writes it to
 * audit_logs — and restoring it was offered. The human chose Direct Sale for
 * all of them instead, and that is the safer of the two: a shared product is
 * one sold from a link, and the failure mode of this choice (a product that
 * can be bought directly when someone wanted a doctor's visit first) is
 * visible on the page and fixable in one click, while the failure mode of the
 * other (a buy button that is still missing) is invisible and was already
 * missed once.
 *
 * ── SELF-CONTAINED, LIKE 2026_08_22's BACKFILL ──
 *
 * No model, no service, no enum — the DB facade and literals only. A
 * migration is a record of what happened to the database on a particular
 * day; if it called PipelineTemplateProvisioner, a later edit to that class
 * would silently change what this migration did months ago.
 *
 * Idempotent: re-running creates nothing and touches only rows that STILL
 * have no journey.
 */
return new class extends Migration
{
    private const KEY_DIRECT_SALE = 'direct_sale_default';

    private const KEY_MEDICAL_PACKAGE = 'medical_package_default';

    /**
     * Verbatim from PipelineTemplateProvisioner::systemTemplates() as of
     * this date, and deliberately copied rather than imported.
     */
    private const JOURNEYS = [
        self::KEY_MEDICAL_PACKAGE => [
            'name' => 'Medical Package (default)',
            'stages' => ['complete_registered', 'waiting_appointment', 'finish_1st_doctor_meeting', 'complete_payment', 'ongoing_next_meeting'],
        ],
        self::KEY_DIRECT_SALE => [
            'name' => 'Direct Sale (default)',
            'stages' => ['complete_registered', 'complete_payment'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::JOURNEYS as $key => $journey) {
            $templateId = DB::table('pipeline_templates')
                ->whereNull('company_id')
                ->where('key', $key)
                ->value('id');

            if ($templateId === null) {
                $templateId = DB::table('pipeline_templates')->insertGetId([
                    'company_id' => null,
                    'key' => $key,
                    'name' => $journey['name'],
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($journey['stages'] as $position => $stage) {
                // updateOrInsert, not insert: a re-run must not violate
                // unique(pipeline_template_id, stage).
                DB::table('pipeline_template_stages')->updateOrInsert(
                    ['pipeline_template_id' => $templateId, 'stage' => $stage],
                    ['company_id' => null, 'position' => $position, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        $directSaleId = DB::table('pipeline_templates')
            ->whereNull('company_id')
            ->where('key', self::KEY_DIRECT_SALE)
            ->value('id');

        if ($directSaleId === null) {
            return; // cannot happen after the block above; not worth a crash if it does
        }

        /*
         * Platform products only, and only the ones still without a journey.
         * A company-owned product is untouched — its journey was never
         * cleared, and overwriting one here would be this migration deciding
         * something no one asked it to decide.
         */
        DB::table('products')
            ->whereNull('company_id')
            ->whereNull('pipeline_template_id')
            ->update(['pipeline_template_id' => $directSaleId, 'updated_at' => $now]);
    }

    public function down(): void
    {
        /*
         * Only the assignment is undone. The two platform journeys are left
         * in place: by the time anyone rolls this back, a product or a
         * category may point at one, and deleting a journey out from under a
         * live product is a far worse outcome than leaving two unused rows.
         */
        $platformIds = DB::table('pipeline_templates')->whereNull('company_id')->pluck('id');

        DB::table('products')
            ->whereNull('company_id')
            ->whereIn('pipeline_template_id', $platformIds)
            ->update(['pipeline_template_id' => null]);
    }
};
