<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Product-view IA item 2.3b — a temporary customer-facing sale price,
// distinct from agent_promotions (2.3a / Agent-view 1.4, which is an
// AGENT bonus, not a price change). product_id is NOT nullable here —
// unlike agent_promotions.product_id (which may be null = "all
// products"), a price promotion is inherently about exactly one
// product's price.
//
// RESOLVED (TASK-047, human-confirmed 2026-07-23): this table is no
// longer display-only. CommissionService::resolveActivePricePromotion()
// now looks up a currently-Active row for the referral's product at the
// exact moment commission is calculated (Complete Payment, BR-4's
// trigger point) and, if found, commissions against
// discounted_price_satang instead of the product's normal price_satang.
// The human's own reasoning: "it must be calculated at the moment the
// client completes payment successfully." See commission_ledger's
// sale_price_satang_at_time / applied_price_promotion_id_at_time columns
// (migration 2026_07_31_100000) for the immutable snapshot of which
// price/promotion was actually used, per row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('discounted_price_satang'); // BR-3
            $table->string('note')->nullable();
            $table->string('status')->default('draft'); // App\Enums\PromotionStatus (reused)
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'status'], 'product_price_promotions_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_promotions');
    }
};
