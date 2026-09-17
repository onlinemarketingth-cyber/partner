<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-16 — THE OTHER HALF OF SHIPPING.
 *
 * ADR-033 added `orders.shipping_recipient_name / _phone / _address` and the
 * payment page that collects them. It never added anywhere for the answer to
 * go: no tracking number, no state, no record of who sent it. The address has
 * been captured and unread since then — a grep of both front-ends finds it
 * displayed on exactly zero screens.
 *
 * Owner, 2026-09-16: "supplier เป็นคนส่งเอง". A supplier shipping on our
 * behalf makes the gap load-bearing — they need to be told what to send, and
 * we need to know whether they sent it, not least because
 * SupplierReleaseTrigger::OnDelivered pays them on the strength of it.
 *
 * ── WHY shipping_status IS NOT NULLABLE ──
 *
 * Unlike almost everything else added this sprint, this one has an honest
 * default. Every order that exists has had nothing shipped, and 'pending' says
 * exactly that — it is not a guess standing in for a missing decision, it is
 * the true state of every historical row. Compare `cost_satang`, which is
 * nullable precisely because 0 would have been a lie about the past.
 *
 * It is set on EVERY order, including ones whose product needs no shipping,
 * because a nullable-when-not-applicable column produces two ways to express
 * "nothing to send" and screens that check only one of them. Whether shipping
 * applies at all is `product.requires_shipping`, which is where that question
 * already lives.
 *
 * ── shipped_by_user_id NULLS, IT DOES NOT CASCADE ──
 *
 * Deleting the supplier's account must not delete the order, and must not
 * erase the fact that a parcel went. The name is gone, the shipment is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_status', 32)
                ->default('pending')
                ->after('shipping_address');

            // Free text on purpose: every courier numbers differently and a
            // validated format would reject the next one we use.
            $table->string('tracking_number')->nullable()->after('shipping_status');

            $table->timestamp('shipped_at')->nullable()->after('tracking_number');

            $table->foreignId('shipped_by_user_id')
                ->nullable()
                ->after('shipped_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipped_by_user_id');
            $table->dropColumn(['shipping_status', 'tracking_number', 'shipped_at']);
        });
    }
};
