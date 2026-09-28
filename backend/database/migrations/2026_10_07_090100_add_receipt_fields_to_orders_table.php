<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-27 — ADR-048. The recipient says the parcel arrived.
 *
 * Owner: "ต้องแก้ไขเป็นผู้รับกดรับสินค้า ถ้า Agent หรือลูกค้าไม่กดรับสินค้าเกิน
 * 15 วันหลัง คู่ค้ากดยืนยันว่าจัดส่งสำเร็จแล้ว พึงเบิกเงินได้".
 *
 * Until now the supplier's own "shipped" click released their money on a
 * deliver-before-pay deal: the payee reported the event that paid them. Now
 * the RECIPIENT confirms — the selling agent in the portal or the customer on
 * the payment link — and if nobody does within the platform's window after
 * shipping, the system confirms for them.
 *
 * `receipt_confirmed_via` records which of the three it was (agent, customer,
 * auto), because "who said it arrived" is the question a disputed payout
 * comes down to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('shipped_by_user_id');
            $table->string('receipt_confirmed_via', 16)->nullable()->after('received_at');
            $table->foreignId('received_by_user_id')->nullable()->after('receipt_confirmed_via')
                ->constrained('users')->nullOnDelete();

            // The auto-confirm sweep: "shipped, not received, shipped before X".
            $table->index(['shipping_status', 'shipped_at'], 'orders_shipping_sweep_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_shipping_sweep_idx');
            $table->dropForeign(['received_by_user_id']);
            $table->dropColumn(['received_at', 'receipt_confirmed_via', 'received_by_user_id']);
        });
    }
};
