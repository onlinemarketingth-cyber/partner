<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-09-09 (human: "ทำกับตัวสินค้าครับ ไม่ใช่หมวด และหากของเก่าปรับเป็น
 * Direct sale หากมีการเพิ่มใหม่ต้องแก้ไขได้").
 *
 * The journey lives ON THE PRODUCT. Not on the category, and not on a
 * company-wide default that nobody can see — every product says outright
 * which journey it sells under.
 *
 * ── WHAT THIS FIXES ──
 *
 * `products.pipeline_template_id` NULL does not mean "no journey", it means
 * "inherit", and the end of that inheritance chain is the seeded Medical
 * Package journey (ลงทะเบียน → รอนัดหมาย → พบแพทย์ → ชำระเงิน). The public
 * share page only offers checkout when payment is the SECOND step, so every
 * product that had simply never been given a journey was un-buyable from its
 * share link — silently, and discovered only when a customer could not pay.
 *
 * The platform products were repaired an hour earlier
 * (2026_09_09_110100). This is the same repair for the company-owned ones,
 * which are the majority and had the same fault for the same reason.
 *
 * ── WHAT IT DELIBERATELY DOES NOT TOUCH ──
 *
 * A product that ALREADY names a journey — including one that names Medical
 * Package on purpose. This only writes down an answer where there was none;
 * it never overrules one somebody chose.
 *
 * ── AND IT IS STILL EDITABLE AFTERWARDS ──
 *
 * "หากมีการเพิ่มใหม่ต้องแก้ไขได้" — the value written here is an ordinary
 * per-product setting, changed from the product form's journey selector like
 * any other. Nothing about this migration makes it sticky, and the "ใช้ค่า
 * จากหมวดสินค้า / บริษัท" option stays available for anyone who wants the
 * old inheriting behaviour back on a particular product.
 *
 * Self-contained (DB facade and literals only), like the two migrations
 * before it: a migration records what happened to the database on a
 * particular day, so a later edit to a model or service must not be able to
 * change what it did.
 *
 * Idempotent, and reversible — `down()` puts the affected rows back to NULL.
 */
return new class extends Migration
{
    private const KEY_DIRECT_SALE = 'direct_sale_default';

    public function up(): void
    {
        $now = now();

        /*
         * One UPDATE per company, because "Direct Sale" is a different ROW in
         * every company (each is provisioned its own copy) — a single global
         * id would point every tenant at one company's journey, which is the
         * BR-6 violation this whole area has been careful about all day.
         */
        $journeyByCompany = DB::table('pipeline_templates')
            ->where('key', self::KEY_DIRECT_SALE)
            ->whereNotNull('company_id')
            ->pluck('id', 'company_id');

        foreach ($journeyByCompany as $companyId => $journeyId) {
            DB::table('products')
                ->where('company_id', $companyId)
                ->whereNull('pipeline_template_id')
                ->update(['pipeline_template_id' => $journeyId, 'updated_at' => $now]);
        }

        // Belt and braces for the platform rows: 2026_09_09_110100 already
        // did these, and a database where that ran is left untouched here.
        $platformJourneyId = DB::table('pipeline_templates')
            ->whereNull('company_id')
            ->where('key', self::KEY_DIRECT_SALE)
            ->value('id');

        if ($platformJourneyId !== null) {
            DB::table('products')
                ->whereNull('company_id')
                ->whereNull('pipeline_template_id')
                ->update(['pipeline_template_id' => $platformJourneyId, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        /*
         * Back to inheriting. This cannot distinguish a row this migration
         * wrote from one an admin has since set to Direct Sale by hand — so
         * it clears every product that currently points at a Direct Sale
         * journey, which is the honest reading of "undo" and is exactly what
         * the state was before.
         */
        $directSaleIds = DB::table('pipeline_templates')->where('key', self::KEY_DIRECT_SALE)->pluck('id');

        DB::table('products')
            ->whereIn('pipeline_template_id', $directSaleIds)
            ->update(['pipeline_template_id' => null]);
    }
};
