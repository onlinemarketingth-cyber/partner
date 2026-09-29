<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-050 — the platform's own payment channels, and the switch that makes
 * every company use them.
 *
 * platform_payment_settings (ONE row, read with first() like
 * platform_mail_settings): the mode, the platform's transfer destination and
 * which online gateway it has switched on. No row = mode 'company', which is
 * exactly how every company took money before this migration.
 *
 * platform_payment_gateway_settings: the platform's Omise / Stripe keys, the
 * same shape as company_payment_gateway_settings (ADR-027 §3) without a
 * company. Kept in its own table rather than as company_id-NULL rows in that
 * one: a NULL there would be one missing WHERE away from being read as some
 * company's keys, and MySQL cannot keep NULLs unique.
 *
 * orders.payment_account: which of the two an order pays into, stamped at
 * creation. Every existing order is 'company', which is where its money went.
 *
 * payment_webhook_events.company_id becomes nullable: a platform webhook that
 * names no order of ours belongs to no company, and is still the row most
 * worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 16)->default('company'); // App\Enums\PaymentAccountScope
            $table->string('payment_provider', 32)->nullable(); // the platform's active ONLINE gateway
            $table->string('promptpay_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_payment_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique(); // App\Enums\PaymentProvider
            $table->text('credentials')->nullable(); // encrypted:array
            $table->boolean('is_live')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_note')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_account', 16)->default('company')->after('gateway_mode');
        });

        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('payment_account'));
        Schema::dropIfExists('platform_payment_gateway_settings');
        Schema::dropIfExists('platform_payment_settings');
        // payment_webhook_events.company_id is left nullable: rows written
        // without a company cannot be squeezed back under NOT NULL.
    }
};
