<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TASK-251 (ADR-036 amendment, human decision 2026-09-04) — one price to
 * start every company from.
 *
 * ── WHAT CHANGED ABOUT THE ADR ──
 *
 * ADR-036 §3 says price is per company, and that is still true: this column
 * is not where anybody's price lives. It is the value each company's copy is
 * CREATED with, once, and every company is free to change its own afterwards
 * — the human's words were "ตั้งได้แต่ค่าเริ่มต้นเดียวกันหมด มาแก้ไขแยกบริษัทได้".
 *
 * The reason it has to exist at all is TASK-251's other half: a new catalog
 * item is now pushed to every company automatically, so something has to
 * decide what price those rows are born with. The alternatives were worse:
 *
 *   • zero — a product listed at 0 บาท is a real number on a real screen; it
 *     is not "unset", and BR-7 exists precisely to stop that being invented;
 *   • nullable price on products — the column is NOT NULL and 15 tables
 *     depend on this row, so relaxing it to model "not priced yet" would
 *     push a null into every reader of a price.
 *
 * NULLABLE HERE, REQUIRED IN THE FORM REQUEST. The column has to accept null
 * for the rows that already exist (none in production today, but the
 * migration cannot know that), while StoreProductCatalogItemRequest requires
 * it from every new item. An item with no default price is simply not
 * propagated — the propagation service skips it and says so, rather than
 * guessing.
 *
 * BR-3: integer satang, like every other money column in this schema.
 * unsignedBigInteger matches products.price_satang exactly, so the value can
 * be copied across with no cast and no range surprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_catalog_items', function (Blueprint $table) {
            $table->unsignedBigInteger('default_price_satang')->nullable()->after('spec_description');
        });
    }

    public function down(): void
    {
        Schema::table('product_catalog_items', function (Blueprint $table) {
            $table->dropColumn('default_price_satang');
        });
    }
};
