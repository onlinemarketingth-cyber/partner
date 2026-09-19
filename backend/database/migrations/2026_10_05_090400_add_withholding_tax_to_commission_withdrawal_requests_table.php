<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gross, tax, net — on the agent payout, in the supplier payout's own words.
 *
 * supplier_withdrawal_requests already carries gross_satang / wht_rate_at_time
 * / wht_satang / net_satang, and its migration states why all three of the
 * money figures are stored rather than two stored and one derived. The same
 * reasoning applies here unchanged, so the same three column names are used.
 *
 * ═══ THE ONE DECISION THIS HAD TO MAKE ═══
 *
 * `amount_satang` STAYS THE GROSS, and takes the place of the supplier
 * table's `gross_satang`. It is not reduced by the tax, and the tax changes
 * nothing about which commission_ledger rows a payout consumes, how much of
 * each it draws, or what availableSatang() reports.
 *
 * That is not a shortcut — it is what withholding means. The agent earned the
 * gross; the company pays part of it to the Revenue Department in the agent's
 * name; the agent is credited with the withheld amount when they file.
 * Netting it out of `amount_satang` would claim the agent earned less than
 * they did, their balance and their withholding certificate would disagree,
 * and the difference would reappear every period as an unexplained shortfall
 * nobody could trace.
 *
 * ═══ WHY THE RATE IS SNAPSHOT, LIKE THE BANK DETAILS ═══
 *
 * Exactly the reasoning the bank_* columns on this table already carry, and
 * the reasoning supplier_settlement_ledger.wht_rate_at_time carries. A
 * request is approved on Monday and transferred on Thursday; a rate read live
 * at transfer time would let an owner's edit in between silently change an
 * amount an admin had already approved and an agent had already been quoted.
 * The snapshot is also what lets the certificate be reproduced from the row
 * years later, which is the point of keeping it.
 *
 * ═══ NULL ON THE RATE, ZERO ON THE MONEY ═══
 *
 * A NULL rate means no withholding applied to this payout — every payout that
 * exists today, and every payout of a company that never sets a rate.
 * `wht_satang` then defaults to 0 and `net_satang` equals the gross, so an
 * existing row backfills to the truth about itself rather than to a guess.
 *
 * `net_satang` is nullable only so rows written before this migration can be
 * told apart from rows genuinely netting to zero; the model resolves a NULL
 * to the gross, so no reader has to know that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_withdrawal_requests', function (Blueprint $table) {
            $table->unsignedInteger('wht_rate_at_time')
                ->nullable()
                ->after('amount_satang');

            $table->unsignedBigInteger('wht_satang') // BR-3
                ->default(0)
                ->after('wht_rate_at_time');

            // What actually leaves the bank account: amount_satang − wht_satang.
            $table->unsignedBigInteger('net_satang') // BR-3
                ->nullable()
                ->after('wht_satang');
        });
    }

    public function down(): void
    {
        Schema::table('commission_withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn(['wht_rate_at_time', 'wht_satang', 'net_satang']);
        });
    }
};
