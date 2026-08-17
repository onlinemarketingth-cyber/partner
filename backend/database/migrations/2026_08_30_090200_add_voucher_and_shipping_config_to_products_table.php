<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-033 (TASK-189) §2.3/§2.5 — BR-7 admin-editable per-product config.
// voucher_usage_quota/voucher_validity_days are nullable = unlimited/never
// expires (never hardcoded — CLAUDE.md §8 rule 2). requires_shipping gates
// D1's conditional shipping-field validation on the public pay page.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('voucher_usage_quota')->nullable()->after('price_satang');
            $table->unsignedInteger('voucher_validity_days')->nullable()->after('voucher_usage_quota');
            $table->boolean('requires_shipping')->default(false)->after('voucher_validity_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['voucher_usage_quota', 'voucher_validity_days', 'requires_shipping']);
        });
    }
};
