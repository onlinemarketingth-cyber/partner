<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-16 — WHAT A SALE COSTS, SO GROSS PROFIT CAN BE COMPUTED AT ALL.
 *
 * Owner: "หน้าที่ให้ทีมบริหาร … ตัวเลขที่จำเป็นต่างๆ สำหรับผู้บริหารในการบริหาร
 * การเงิน". Gross profit was the one figure on that list the schema could not
 * answer in any form: `products` carried a selling price and nothing else, so
 * revenue minus commission was as far as any report could go.
 *
 * ── TWO COLUMNS, AND THE SECOND ONE IS THE POINT ──
 *
 * `products.cost_satang` is the current cost — what the next sale will cost.
 * It is the field somebody edits.
 *
 * `orders.cost_satang_at_time` is the cost of THIS sale, copied when the order
 * is created and never touched again. That is the one a report reads.
 *
 * Without the snapshot, last quarter's gross profit would be recomputed with
 * today's cost every time the page loaded — a supplier price rise in September
 * would silently rewrite June's margin, and nobody would be able to say why
 * the number moved. That is not hypothetical here: it is exactly the defect
 * the existing "มุมมองสินค้า" screen has with PRICE (sold_count × the
 * product's CURRENT price_satang), which this pair exists not to repeat.
 * `orders.amount_satang` has always been a price snapshot for the same
 * reason — this is its twin.
 *
 * ── WHY BOTH ARE NULLABLE, AND WHY THAT IS NOT LAZINESS ──
 *
 * Every product that exists today has no cost, and every order ever placed has
 * none either. A default of 0 would make all of that history look like it was
 * pure profit — a confident, wrong number, which is worse than an absent one.
 * NULL means "nobody recorded this", and the report is required to say so
 * rather than count it (see BusinessOverviewService and the screen's
 * disclosure box).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Unsigned: a negative cost is not a discount, it is a typo.
            $table->unsignedBigInteger('cost_satang')->nullable()->after('price_satang');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_satang_at_time')->nullable()->after('amount_satang');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cost_satang');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cost_satang_at_time');
        });
    }
};
