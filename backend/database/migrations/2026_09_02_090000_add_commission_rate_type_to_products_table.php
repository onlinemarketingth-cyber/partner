<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-197 §2.1 — hoists the %/fixed-amount FORMAT choice from a per-rule
// field (commission_rules.rate_type) to a per-product setting. Same
// nullable/no-default pattern as commission_plan_type (2026_07_23_090000)
// and affiliate_override_mode (2026_09_01_090000): NULL = "not yet
// configured for this product" — the frontend defaults new product-scoped
// rule forms to 'percentage' when null (same as today's per-rule default),
// and CommissionRuleService stamps this column from the product's FIRST
// commission_rules row once it's created (TASK-197 §2.2's "first rule
// decides the format" behavior).
//
// This migration never touches existing rows (BR-7 spirit / TASK-197 §1:
// "no backfill/migration of old rows — this is a going-forward change").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('commission_rate_type')->nullable()->after('affiliate_override_mode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('commission_rate_type');
        });
    }
};
