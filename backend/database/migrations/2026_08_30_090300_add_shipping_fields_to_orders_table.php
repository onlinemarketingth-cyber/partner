<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-033 (TASK-189) §2.5 — same shape as reward_redemptions.shipping_*
// (TASK-042), captured at the point of paying (public /pay/{token} slip
// submission), never pulled from a stored client profile.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_recipient_name')->nullable()->after('paid_at');
            $table->string('shipping_phone')->nullable()->after('shipping_recipient_name');
            $table->text('shipping_address')->nullable()->after('shipping_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_recipient_name', 'shipping_phone', 'shipping_address']);
        });
    }
};
