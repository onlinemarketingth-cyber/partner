<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-27 — ADR-048. One platform-wide supplier setting, for now.
 *
 * `auto_receive_days`: how long after a supplier marks an order shipped the
 * system treats it as received if neither the agent nor the customer says so.
 *
 * The owner named 15 days and chose ONE value for the whole platform rather
 * than one per deal. It is seeded here because he stated it, and editable on
 * จัดการคู่ค้า because it is a business value (BR-7), not a constant.
 *
 * A single-row table rather than a column on platform_commission_settings:
 * this is not a commission setting, and borrowing that row would put a
 * supplier rule behind the commission screen's permissions and cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_platform_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('auto_receive_days');
            $table->timestamps();
        });

        DB::table('supplier_platform_settings')->insert([
            'auto_receive_days' => 15,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_platform_settings');
    }
};
