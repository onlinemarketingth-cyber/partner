<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an order remembers about how it is being paid.
 *
 * ── THE ORDER REMEMBERS ITS PROVIDER, THE COMPANY DOES NOT DECIDE IT TWICE ──
 *
 * A company can switch its active gateway. An order created before the switch
 * already has a /pay/{token} link sitting in somebody's LINE chat with
 * instructions the customer has read. You cannot change the payment
 * instructions on a link already sent.
 *
 * So the provider is stamped at order creation and never re-read from the
 * company afterwards. Switching affects NEW orders only. Without this column
 * the pay page would silently re-render as a different payment method the
 * moment an admin flipped a setting, mid-transaction, for every open link.
 *
 * ── gateway_mode IS NOT COSMETIC ──
 *
 * A charge made with a test key looks exactly like revenue in every report
 * unless the order says otherwise. Recorded per ORDER rather than read from
 * the settings row, for the same reason as above: the settings can change and
 * history cannot.
 *
 * ── gateway_charge_id IS THE IDEMPOTENCY KEY ──
 *
 * Gateways retry webhooks; that is normal operation, not an error. Today
 * OrderService::confirmPayment() guards with `status === Paid` in PHP, which
 * does not survive two webhooks arriving at once — and what gets written
 * twice is a BR-4 commission ledger row, which is immutable by definition and
 * therefore cannot be un-written.
 *
 * A UNIQUE index is the guard, because it is the only one the database
 * enforces regardless of how many workers race. Nullable, because the manual
 * slip flow has no charge id — and NULLs do not collide in a MySQL unique
 * index, so every manual order coexists happily.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_provider')->default('manual')->after('payment_method');
            $table->string('gateway_mode')->default('live')->after('payment_provider');
            $table->string('gateway_charge_id')->nullable()->after('payment_reference');

            $table->unique('gateway_charge_id', 'orders_gateway_charge_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_gateway_charge_unique');
            $table->dropColumn(['payment_provider', 'gateway_mode', 'gateway_charge_id']);
        });
    }
};
