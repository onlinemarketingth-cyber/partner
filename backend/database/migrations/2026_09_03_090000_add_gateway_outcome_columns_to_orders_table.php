<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an order remembers about payment attempts that did NOT succeed.
 *
 * ── WHY THESE COLUMNS EXIST ──
 *
 * Three of the five webhook events this system subscribes to only ever
 * reached a log file: a failed async payment, an expired checkout session,
 * and a refund reported by the gateway. `applyFailed()` called Log::info()
 * and returned. Nothing appeared on any screen, so an admin looking at an
 * order that a customer swore they had tried to pay saw an untouched
 * "awaiting payment" row and no way to tell the difference between "never
 * opened the link" and "tried three times and the card was declined".
 *
 * ── WHY NOT A STATUS CHANGE ──
 *
 * None of these three end an order. A declined card can be retried; an
 * expired Checkout Session is replaced by a new one the moment the customer
 * opens the same /pay link again. Moving the order to a terminal status on
 * any of them would cancel sales that were about to complete. They are
 * ANNOTATIONS on a still-open order, which is why they are their own
 * columns and not new OrderStatus cases.
 *
 * ── refund_reported_at IS NOT refunded_at ──
 *
 * `refunded_at` / `refund_reason` (2026-08-21 audit, V15) are written ONLY
 * by CommissionReversalService, because reversing a sale reverses an agent's
 * commission and BR-4 ledger rows are immutable. That must stay a human
 * decision made inside this company.
 *
 * These columns record something different: the GATEWAY says a refund
 * happened. It is a claim from outside that a human now has to act on, and
 * conflating it with the internal reversal would let a webhook claw money
 * back from an agent's balance with nobody reviewing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Human-readable and already localised at the point it is set —
            // this is rendered to an admin as-is, not translated later.
            $table->string('last_payment_error')->nullable()->after('gateway_charge_id');
            $table->timestamp('last_payment_error_at')->nullable()->after('last_payment_error');

            $table->timestamp('refund_reported_at')->nullable()->after('last_payment_error_at');
            // Signed bigint like every other satang column (BR-3): a partial
            // refund is a real case and the amount is the whole point.
            $table->bigInteger('refund_reported_satang')->nullable()->after('refund_reported_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'last_payment_error',
                'last_payment_error_at',
                'refund_reported_at',
                'refund_reported_satang',
            ]);
        });
    }
};
