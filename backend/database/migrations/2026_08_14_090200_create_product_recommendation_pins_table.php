<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-068 / ADR-020 row 4, manual half of the hybrid "recommended for
// you" row (decision #1, human-confirmed 2026-07-31): admin manually
// pins products (ordered by sort_order) first; ProductController::
// recommended() fills any remaining slots live from ProductGradingService's
// existing ABC output (TASK-040) — no separate table needed for the
// auto-fill half. Unique on (company_id, product_id): a product can only
// be pinned once per company (re-pinning just changes its sort_order/
// is_active via update, not a duplicate row).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_recommendation_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'product_id'], 'product_recommendation_pins_company_product_unique');
            $table->index(['company_id', 'is_active', 'sort_order'], 'product_recommendation_pins_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recommendation_pins');
    }
};
