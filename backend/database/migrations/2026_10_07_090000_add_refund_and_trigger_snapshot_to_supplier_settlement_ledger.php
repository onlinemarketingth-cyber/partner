<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-27 — ADR-048. Two things the supplier ledger could not say.
 *
 * ── 1. A REFUND ──
 *
 * Owner: "คืนเงินลูกค้าแล้ว … หักครั้งถัดไป". The ledger is immutable, so a
 * refund cannot edit the sale row; it is a second row of the opposite sign
 * (`entry_kind = refund`, pointing back at the sale through
 * `reverses_ledger_id`). That needs the one-row-per-order UNIQUE on order_id to
 * become one row per order PER KIND — the database still refuses a second sale
 * row for the same order, which is what that index was protecting.
 *
 * ── 2. WHICH TRIGGER THE SALE WAS MADE UNDER ──
 *
 * Release used to read the supplier's CURRENT trigger. Change a deal from
 * "on redemption" to "on payment" and every row still waiting for a
 * redemption waited forever, because the redemption now looked for the wrong
 * trigger. The trigger is a term of the sale, snapshotted like GP and tax.
 *
 * Existing rows are backfilled with their supplier's current trigger — the
 * only record there is of what applied when they were written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->string('entry_kind', 16)->default('sale')->after('order_id');
            $table->foreignId('reverses_ledger_id')->nullable()->after('entry_kind')
                ->unique('ssl_reverses_ledger_unique')
                ->constrained('supplier_settlement_ledger')->nullOnDelete();
            $table->string('release_trigger_at_time', 32)->nullable()->after('wht_rate_at_time');
        });

        // New index first: order_id carries a foreign key, and MySQL will not
        // drop the only index a foreign key can use.
        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->unique(['order_id', 'entry_kind'], 'ssl_order_kind_unique');
        });

        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->dropUnique('supplier_settlement_ledger_order_id_unique');
        });

        foreach (DB::table('suppliers')->whereNotNull('release_trigger')->get(['id', 'release_trigger']) as $supplier) {
            DB::table('supplier_settlement_ledger')
                ->where('supplier_id', $supplier->id)
                ->whereNull('release_trigger_at_time')
                ->update(['release_trigger_at_time' => $supplier->release_trigger]);
        }
    }

    public function down(): void
    {
        DB::table('supplier_settlement_ledger')->where('entry_kind', 'refund')->delete();

        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->unique('order_id', 'supplier_settlement_ledger_order_id_unique');
        });

        Schema::table('supplier_settlement_ledger', function (Blueprint $table) {
            $table->dropUnique('ssl_order_kind_unique');
            $table->dropForeign(['reverses_ledger_id']);
            $table->dropUnique('ssl_reverses_ledger_unique');
            $table->dropColumn(['entry_kind', 'reverses_ledger_id', 'release_trigger_at_time']);
        });
    }
};
