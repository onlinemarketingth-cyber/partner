<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TASK-253 / ADR-040 §1 — the ONLY thing that differs per company about a
 * shared product.
 *
 * ── WHAT LIVES HERE, AND WHAT DELIBERATELY DOES NOT ──
 *
 * The human's sentence was "แยกกันแค่ราคา/ค่าคอม/เปิด-ปิดขาย". Everything
 * else about the product — name, description, spec, brand, category, media —
 * has exactly one home on the product row itself, because a second copy per
 * company is the copy model this ADR exists to undo.
 *
 * `price_satang` is NULLABLE and that is the human's decision, recorded in
 * ADR-040's table: a company that has not set its own price INHERITS the
 * central one rather than being unable to sell ("ถ้าไม่มีการแก้ไขให้ใช้ราคา
 * กลางไปก่อน"). BR-7 is satisfied because the fallback is a number a Super
 * Admin typed on the product, not one this system invented.
 *
 * `is_active` DOES NOT fall back. It defaults to false, and that asymmetry is
 * deliberate: knowing what a product would cost is not the same as deciding
 * to sell it. The earlier "ปิดไว้ก่อน" decision survives the re-model — a
 * product appearing in a company's catalog must never be a product that
 * company has started selling without anybody choosing to.
 *
 * COMMISSION IS NOT HERE, ON PURPOSE. It already lives per company in
 * `commission_rules` (product_id + company_id, BR-2), which needs no change
 * at all: those rows point at a product id that does not move. Adding
 * commission columns here would create a second place to look and a second
 * place to disagree.
 *
 * ── THE UNIQUE INDEX IS THE POINT ──
 *
 * One row per (company, product). Two rows would mean two prices for the same
 * listing with nothing to say which is real — the exact class of ambiguity
 * this whole re-model is removing.
 *
 * cascadeOnDelete on both sides: a settings row describes a relationship
 * between a company and a product, and it is meaningless once either is
 * really gone. (Both models soft-delete in practice, so this fires only on a
 * true purge.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_product_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // BR-3 — integer satang, same type as products.price_satang so the
            // central value can be copied or compared with no cast. NULL is
            // "this company has not set its own price; use the product's".
            $table->unsignedBigInteger('price_satang')->nullable();

            // No fallback, see the docblock. A company opens its own shop.
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->unique(['company_id', 'product_id'], 'company_product_settings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_product_settings');
    }
};
