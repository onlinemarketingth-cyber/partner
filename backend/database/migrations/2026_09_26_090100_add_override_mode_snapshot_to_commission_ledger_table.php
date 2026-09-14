<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2026-09-13 — BR-4's half of the override-mode work.
 *
 * The same argument the commission_basis snapshot was added on. A leader's
 * ledger row records a rate (2%) and a sale price (10,000) and an amount — and
 * under DeductFromCommission that amount is 6, not 200, because the 2% was
 * taken of the seller's commission rather than of the sale. Without this
 * column the row's own arithmetic does not check out, in a table BR-4 forbids
 * anybody from correcting, and the setting that would explain it is a company
 * toggle somebody may have changed since.
 *
 * NULL on every row written before today, and on every Direct row — the mode
 * only describes an override. NULL therefore reads as "additive, as it always
 * was", and every consumer treats it that way explicitly rather than assuming.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->string('override_mode_at_time')
                ->nullable()
                ->after('commission_base_satang_at_time');
        });
    }

    public function down(): void
    {
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropColumn('override_mode_at_time');
        });
    }
};
