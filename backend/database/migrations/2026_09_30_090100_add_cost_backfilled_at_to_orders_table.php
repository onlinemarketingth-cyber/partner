<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-16 — TELLING A REAL COST SNAPSHOT APART FROM AN ESTIMATE.
 *
 * Owner, hours after the cost field shipped: "ผมใส่ต้นทุนสินค้าย้อนหลังแล้ว
 * กำไรขึ้นต้นไม่ขึ้นหรือไงครับ".
 *
 * They were right to expect a number. `orders.cost_satang_at_time` is written
 * when the ORDER is created, so a cost typed in today reaches tomorrow's sales
 * and never yesterday's — which is correct as an ongoing rule and useless as a
 * starting position, because every sale this company has ever made predates
 * the column existing. Left alone, the gross-profit figure would have stayed
 * empty until the catalogue turned over.
 *
 * So there is a backfill (OrderCostBackfillService), and it does the thing
 * this design exists to prevent: it applies TODAY's cost to a PAST sale.
 *
 * That is defensible exactly once — there was no cost recorded at the time, so
 * the current one is the only estimate available — and it must never be
 * mistaken afterwards for what was actually paid. Hence this column: an order
 * carrying it says "this cost is an estimate applied later", the overview
 * counts them, and the screen says so under the margin they support.
 *
 * A backfilled order is never backfilled twice: the service only touches rows
 * whose cost is still NULL, so a later change to the product's cost cannot
 * quietly rewrite a margin that has already been reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cost_backfilled_at')->nullable()->after('cost_satang_at_time');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cost_backfilled_at');
        });
    }
};
