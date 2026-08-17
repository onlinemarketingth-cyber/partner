<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-011 Section 1 (TASK-027): per-product override of the company's
// commission_plan_type. NULL = inherit Company::commission_plan_type
// (the existing, only-working default). Nullable and defaultless by
// design — every existing product keeps today's behavior unchanged
// (100% backward compatible) until a Company Admin explicitly opts a
// specific product into a different plan type (e.g. Affiliate on one
// product while the company default stays Unilevel).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('commission_plan_type')->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('commission_plan_type');
        });
    }
};
