<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-214 — the team-leader override rate gains the SAME scope dimension
 * the selling agent's rate has had since TASK-028, and loses the cert-tier
 * dimension, on two explicit human rulings (2026-08-19):
 *
 *   "อัตราหัวหน้าทีมยังผูกกับ cert tier ไหม → ไม่ต้องผูก"
 *   "ลำดับ สินค้า > หมวดหมู่ > ทั้งบริษัท เหมือนอัตราตัวแทนเป๊ะ ๆ ใช่ไหม
 *    → ตามที่คุณเสนอ"
 *
 * ═══ WHY manager_cert_tier_id IS NOT DROPPED ═══
 * Exactly the treatment ADR-035 gave commission_rules.cert_tier_id: the
 * column stays, and resolution stops filtering by it. Dropping it would
 * destroy the only record of what a legacy row MEANT when it was written,
 * which is the information a human needs to decide which of several
 * per-tier rows should survive the collapse
 * (commission:collapse-override-tiers). It becomes nullable because new
 * rows have nothing to put in it.
 *
 * ═══ WHAT THIS DOES NOT CHANGE ═══
 * Whether a manager is ELIGIBLE at all. CommissionService still requires
 * the manager to hold a passed cert tier before paying them an override —
 * that check is a gate, not a rate key, and ADR-035's own reasoning is
 * that certification is exactly that: "a binary access gate, never a rate
 * multiplier". The ruling above was about the RATE. If eligibility should
 * change too, that is a separate decision with a separate blast radius.
 *
 * ═══ EXISTING ROWS ═══
 * A row with both new columns NULL is a company-wide default — which is
 * precisely what every pre-TASK-214 row already was in practice (they
 * applied to every product). So no backfill: the semantics of existing
 * data are unchanged by this migration alone.
 *
 * The one real hazard is a company holding SEVERAL rows that differed only
 * by manager_cert_tier_id. Those were legitimately distinct before and
 * become an ambiguous overlap the moment resolution stops reading the
 * tier. This migration does not silently pick a winner — which rate
 * survives is a business decision (BR-7). `commission:collapse-override-tiers`
 * asks a human, and TASK-213 r2's overlap detector shows any that are left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_override_rules', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('company_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('product_category_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();

            // Same lookup shape as commission_rules_category_lookup_idx —
            // resolution walks product, then category, then the both-null
            // company default.
            $table->index(['company_id', 'product_id'], 'commission_override_rules_product_idx');
            $table->index(['company_id', 'product_category_id'], 'commission_override_rules_category_idx');
        });

        // Native ->change() (Laravel 12, no doctrine/dbal). Kept in its own
        // Schema::table() call because a column modification and an ADD
        // COLUMN + FK in one statement behave differently across drivers,
        // and this file has to run identically on MySQL and on the SQLite
        // the test suite uses.
        Schema::table('commission_override_rules', function (Blueprint $table) {
            $table->foreignId('manager_cert_tier_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('commission_override_rules', function (Blueprint $table) {
            $table->dropIndex('commission_override_rules_product_idx');
            $table->dropIndex('commission_override_rules_category_idx');
            $table->dropConstrainedForeignId('product_id');
            $table->dropConstrainedForeignId('product_category_id');
        });

        // Rows created after this migration may legitimately have a NULL
        // tier, so reversing the nullability could fail on real data. That
        // is correct: down() must not invent a tier to satisfy the old
        // constraint. Operators rolling back are expected to have no
        // TASK-214-era rows, or to resolve them first.
        Schema::table('commission_override_rules', function (Blueprint $table) {
            $table->foreignId('manager_cert_tier_id')->nullable(false)->change();
        });
    }
};
