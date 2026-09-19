<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-LEVEL leader rates, and a depth the chain stops at.
 *
 * ═══ WHAT WAS WRONG ═══
 *
 * Unilevel resolved ONE rate per sale and paid it to every manager up the
 * chain, with no cap on how far up the chain went. Both halves are unlike how
 * the plan works anywhere in the industry, where a rate is quoted per level
 * (10% / 5% / 3%) and the payout stops at a stated depth.
 *
 * The consequence was not theoretical. OverrideDeductionGuard exists because
 * the deducting modes could otherwise pay out more than the seller earned, and
 * the only lever it had was the RATE: it refuses anything above
 * sellerCommission ÷ deepestChain. So the deeper the organisation grew, the
 * lower the rate anybody was allowed to set — for everybody, retroactively,
 * across the whole company. A company cannot run a compensation plan whose
 * headline number shrinks as it recruits.
 *
 * ═══ NULL IS TODAY'S BEHAVIOUR, ON PURPOSE ═══
 *
 * `level` NULL = "this rate applies at every level", which is exactly the
 * single-rate rule that exists now. A company that never adds a levelled row
 * and never sets a depth computes byte-identically to before this migration —
 * that equality is the whole safety argument for deploying it to live
 * companies, the same one `commission_basis` was built on.
 *
 * A levelled row wins over the NULL row for its own level; the NULL row keeps
 * serving every level nobody has priced. That way a company can quote level 1
 * and 2 explicitly and leave a catch-all for the rest, or price every level
 * and delete the catch-all, without a migration in between.
 *
 * ═══ WHY NOT A SEPARATE TABLE ═══
 *
 * commission_matrix_level_rates is a separate table, and this deliberately is
 * not. Matrix rates are keyed by level and NOTHING else. A Unilevel leader
 * rate is already scoped by product, then category, then company (TASK-214),
 * carries its own effective_from/effective_to window and may override the
 * company's deduction mode. Level is one more dimension of THAT row, and
 * splitting it out would mean maintaining the scope ladder and the date
 * window in two places — the exact duplication Product::effectivePlanType()
 * and CommissionBasisResolver exist to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_override_rules', function (Blueprint $table) {
            $table->unsignedInteger('level')->nullable()->after('product_category_id');
            $table->index(['company_id', 'level']);
        });

        Schema::table('companies', function (Blueprint $table) {
            /*
             * NULL = no cap, which is what the walk does today (its only stop
             * is MAX_OVERRIDE_CHAIN_DEPTH = 100, a defensive circuit breaker
             * against a corrupted chain, explicitly documented as not a
             * business rule).
             *
             * No default is seeded. BR-7: how many levels a company pays is a
             * business decision and nothing here is entitled to invent one —
             * a company that wants a cap sets it, a company that does not
             * keeps what it has.
             */
            $table->unsignedInteger('max_override_depth')->nullable()->after('commission_override_mode');
        });
    }

    public function down(): void
    {
        Schema::table('commission_override_rules', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'level']);
            $table->dropColumn('level');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('max_override_depth');
        });
    }
};
