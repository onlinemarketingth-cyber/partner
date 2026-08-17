<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// TASK-047 — human-confirmed decision (previously flagged as
// "// TODO: CONFIRM" in 2026_07_27_090000_create_product_price_promotions_table.php):
// commission is now computed against a product's DISCOUNTED price when an
// active ProductPricePromotion covers it at the moment CommissionService
// fires (Complete Payment, BR-4's trigger point) — never the referral's
// submission-time price, since the human's own reasoning was "it must be
// calculated at the moment the client completes payment successfully."
//
// These 2 columns are an immutable SNAPSHOT of what price/promotion was
// actually used for THIS row's calculation — same "capture at time of
// firing, never recompute later" pattern BR-4 already established for
// cert_tier_id_at_time/rate_type_applied/rate_applied. Both nullable:
// only rows created by CommissionService::recordDirectSale()/
// recordOverrides() (Unilevel) set them — BinaryMatch/MatrixOverride/
// StairstepOverride/GenerationOverride/PromotionBonus/Renewal rows leave
// them null (out of scope for this task, see TASK-047 doc — their
// underlying $productPriceSatang IS already promotion-aware via the same
// shared variable in CommissionService::recordForReferral(), so payout
// AMOUNTS are correct everywhere; only the reference/display snapshot
// columns are narrower in this first pass).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_price_satang_at_time')->nullable()->after('product_id');
            $table->foreignId('applied_price_promotion_id_at_time')
                ->nullable()
                ->after('sale_price_satang_at_time')
                ->constrained('product_price_promotions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_price_promotion_id_at_time');
            $table->dropColumn('sale_price_satang_at_time');
        });
    }
};
