<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withholding tax on an AGENT's payout (ภาษีหัก ณ ที่จ่าย).
 *
 * ═══ WHAT WAS MISSING ═══
 *
 * A Thai company paying commission to an individual withholds tax at the
 * point of transfer and remits it to the Revenue Department on that person's
 * behalf (ภ.ง.ด.3 / ภ.ง.ด.53). The supplier side of this system has modelled
 * that since 2026-10-01 — suppliers.wht_rate, wht_rate_at_time on every
 * settlement row, gross/wht/net on every payout. The AGENT side had nothing:
 * it computed what the agent had earned and told an admin to transfer
 * exactly that.
 *
 * So a company running this has been either transferring the gross — leaving
 * itself liable for tax it failed to withhold — or doing the subtraction by
 * hand in its banking app, with this system's own records showing a figure
 * nobody ever transferred. Either way the agent cannot be given a
 * withholding certificate from anything the system knows.
 *
 * ═══ DELIBERATELY THE SUPPLIER'S SHAPE, NOT A SECOND ONE ═══
 *
 * `wht_rate`, unsigned int, basis points, nullable — the same column name,
 * type, scale and null-meaning as `suppliers.wht_rate`. The two flows pay
 * two different kinds of payee under the same obligation; giving them two
 * vocabularies for it would mean every report that spans both has to
 * translate, and every future reader has to work out whether the difference
 * is meaningful. It is not.
 *
 * ═══ NULL IS OFF, AND OFF IS THE DEFAULT ═══
 *
 * NULL = no withholding, which is exactly what happens today. Nothing is
 * seeded. THE RATE IS NOT OURS TO CHOOSE — this is BR-7, and this instance
 * of it is the law rather than a preference: which rate applies depends on
 * how the payment is classified and on whether the payee is an individual or
 * a juristic person, and a wrong number here is a filing error with a
 * penalty attached. The owner enters it; nothing here suggests one.
 *
 * ═══ WHY IT LIVES ON THE COMPANY ═══
 *
 * It is a property of the PAYER's obligation, not of the sale or the plan.
 * Per-agent variation (a payee registered as a juristic person, a payee with
 * an exemption) is real and deliberately NOT modelled yet: inventing a shape
 * for it is worse than leaving the admin to handle the exception knowingly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('wht_rate')
                ->nullable()
                ->after('min_withdrawal_satang');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('wht_rate');
        });
    }
};
