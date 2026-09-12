<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2026-09-12 — BR-4's half of the PV work.
 *
 * ── WHY A SNAPSHOT IS NOT OPTIONAL HERE ──
 *
 * A commission_ledger row may never be edited (BR-4), so its own columns
 * are the ONLY surviving explanation of the number in `amount_satang`.
 * Until today that explanation was complete: rate_type_applied x
 * rate_applied applied to sale_price_satang_at_time, and the arithmetic
 * checks out from the row alone.
 *
 * PV breaks that, and quietly. A row computed off PV shows a rate of 5%
 * and a sale price of 8,900 baht and an amount that is not 5% of 8,900 —
 * arithmetic that looks like a bug, in the one table nobody may correct.
 * Worse, the product's PV is mutable and the company's basis is a
 * settings toggle, so a year later there is no way to re-derive which
 * base was used: the two things you would go and look at have both moved.
 *
 * So both halves are recorded on the row itself:
 *
 *   commission_basis_at_time        which base this row used
 *   commission_base_satang_at_time  the exact figure the rate was applied to
 *
 * `sale_price_satang_at_time` keeps its existing meaning untouched — what
 * the customer paid. It is NOT overloaded to carry PV, deliberately:
 * every existing reader (reports, the reversal service, the agent's
 * commission screen) would have started showing points labelled as baht.
 *
 * ── NULLABLE, FILLED GOING FORWARD ──
 *
 * Rows written before today are all price-basis by definition, and
 * backfilling them would mean writing to a table this codebase enforces
 * as immutable at the model layer (CommissionLedger::updating()). NULL
 * therefore reads as "price, as it always was" — every consumer added
 * below treats it that way explicitly rather than assuming it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->string('commission_basis_at_time')
                ->nullable()
                ->after('applied_price_promotion_id_at_time');

            $table->unsignedBigInteger('commission_base_satang_at_time')
                ->nullable()
                ->after('commission_basis_at_time');
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropColumn(['commission_basis_at_time', 'commission_base_satang_at_time']);
        });
    }
};
