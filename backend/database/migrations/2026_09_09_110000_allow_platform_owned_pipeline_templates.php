<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-09 (human, from production — the buy button had vanished from a
 * live share link: "เช็คหน่อยระบบจ่ายเงินชำระเงินทำไมมันหายไปจากหน้านี้",
 * then the ruling: "เวลา set เป็นค่า product กลาง ข้อมูลพื้นฐานที่ไม่ใช่
 * ราคา กับค่าคอม นั้นต้องไปด้วยกันหมด").
 *
 * ── WHAT HAD HAPPENED ──
 *
 * `catalog:promote-products` CLEARED `products.pipeline_template_id` when it
 * promoted a product to the platform, on the stated reasoning that "a
 * pipeline template belongs to ONE company, so a shared product cannot carry
 * one". The journey then re-resolved per company: category → company default
 * → and, when neither is set, the seeded Medical Package journey
 * (ลงทะเบียน → รอนัดหมาย → พบแพทย์ → ชำระเงิน).
 *
 * The public share page shows a buy button only when payment is the SECOND
 * stage (PipelineTemplateResolver::paymentReachableFromEntry — you cannot
 * pay before the doctor's visit the journey says comes first). So promoting
 * a Direct Sale product to the platform silently turned off the ability to
 * buy it from every share link that had already been sent out, with nothing
 * on any screen saying so and no way to put it back: the product form hides
 * the journey selector for a shared product, and no screen in the admin
 * console sets a company's default journey.
 *
 * ── THE FIX IS THE ONE ADR-040 ALREADY MADE TWICE ──
 *
 * A brand had the same problem, and so did a category: both are now
 * NULLABLE-company rows, where NULL means "the platform owns this and every
 * company uses it". A journey is the same KIND of thing — part of what the
 * product IS, not a per-company decision about it. The human's ruling names
 * the boundary exactly: price and commission are per company; everything
 * else travels with the product.
 *
 * So `company_id` becomes nullable here too, and a promoted product carries
 * its journey across instead of losing it.
 *
 * ── THE STAGES TABLE COMES ALONG ──
 *
 * `pipeline_template_stages.company_id` is denormalised from its parent
 * (BR-6, §5 rule 1) and carries plain TenantScope, which silently excludes
 * NULL. Leaving it NOT NULL would make a platform template's stage list
 * unwritable; leaving it on TenantScope would make it unreadable — a journey
 * whose stages nobody can see is worse than no journey, because
 * assertValidStageSequence would then reject it as empty.
 *
 * ── ON unique(company_id, key) ──
 *
 * Deliberately left as it is. MySQL treats NULLs as distinct in a unique
 * index, so it does not stop two platform templates sharing a key — that
 * guarantee moves to PipelineTemplateProvisioner, which is the only writer
 * of platform rows and looks one up before creating it. The index still does
 * its real job: keeping one COMPANY from having two templates with the same
 * key.
 *
 * ->change(), not a shadow-table rebuild — see
 * 2026_09_07_090000_allow_platform_owned_products_brands_and_categories.php
 * for why (Laravel 12 alters natively; its SQLite grammar rebuilds the table
 * itself and preserves the foreign keys).
 */
return new class extends Migration
{
    private const TABLES = ['pipeline_templates', 'pipeline_template_stages'];

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
         * Reversible only while no platform journey exists. Once one does,
         * NOT NULL would need a company to assign it to, and there is no
         * honest answer — the point of the row is that it belongs to all of
         * them.
         */
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('company_id')->nullable(false)->change();
            });
        }
    }
};
