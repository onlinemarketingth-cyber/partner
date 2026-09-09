<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2026-09-09 (human, from production: uploading a photo onto product #11 —
 * "GENESENN 1-Year Vital Blueprint" — returned 500. "Upload รูปแล้ว error").
 *
 * ── WHAT BROKE ──
 *
 * Product #11 was promoted to the platform on 2026-09-07, so its
 * `company_id` is NULL (ADR-040 §1). ProductMediaService::store() copies the
 * product's company onto the media row:
 *
 *     'company_id' => $product->company_id,
 *
 * and `product_media.company_id` is still `foreignId()->constrained()` — NOT
 * NULL. So the insert failed at the database, which is a 500, not a
 * validation error: nothing the person typed was wrong.
 *
 * ── WHY FOUR TABLES AND NOT ONE ──
 *
 * The same line, verbatim, exists in ProductSpecService,
 * ProductSpecAttachmentService and ProductSalesMaterialService. Only the
 * media tab had been used on a promoted product yet; the other three are the
 * same 500 waiting for the next tab. Fixing one and leaving three is how a
 * person learns to distrust the screen.
 *
 * ── WHY NULL IS THE RIGHT ANSWER, NOT "the company that uploaded it" ──
 *
 * These four tables are the product's OWN content — its photos, its spec
 * sheet, its sales材料. ADR-040 says a platform product is ONE row that every
 * company sells; its photographs are part of that row, not something each
 * company keeps a private copy of. Stamping the uploader's company would
 * scope the photos to one tenant and leave every other company selling a
 * product with no pictures — which is the copy-per-company model the human
 * rejected on 2026-09-05, reintroduced through the back door.
 *
 * Per-company facts about a shared product already have their own home:
 * `company_product_settings` (price, on/off), `commission_rules`, and
 * `product_recommendation_pins`. Those stay NOT NULL, correctly — they are
 * each company's own decisions, not the product's content.
 *
 * ── THE READ SIDE IS PART OF THIS CHANGE ──
 *
 * All four models carried plain TenantScope (`where company_id = :own`),
 * which silently excludes NULL — so a Company Admin would have seen the
 * shared product with an empty gallery, and route-model binding would have
 * 404'd every one of its photos. They move to SharedOrTenantScope in the
 * same commit; a nullable column read through TenantScope is worse than the
 * NOT NULL it replaced.
 *
 * ->change(), not a shadow-table rebuild — see
 * 2026_09_07_090000_allow_platform_owned_products_brands_and_categories.php
 * for why (Laravel 12 alters natively; its SQLite grammar rebuilds the table
 * itself and preserves the foreign keys).
 */
return new class extends Migration
{
    /** The product's own content: one product, one set, shared by everybody. */
    private const TABLES = [
        'product_media',
        'product_specs',
        'product_spec_attachments',
        'product_sales_materials',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('company_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        /*
         * Reversible only while no platform-owned product has content.
         * Once one does, NOT NULL would need a company to assign its photos
         * to, and there is no honest answer — the point of the row is that it
         * belongs to all of them.
         */
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('company_id')->nullable(false)->change();
            });
        }
    }
};
